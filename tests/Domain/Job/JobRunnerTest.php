<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Job;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobHandler;
use Reptilienmarkt\Domain\Job\JobRunner;
use Reptilienmarkt\Domain\Job\JobStatus;
use Reptilienmarkt\Infra\Persistence\PdoJobRepository;
use Reptilienmarkt\Support\Log\NullLogger;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;
use RuntimeException;

#[CoversClass(JobRunner::class)]
#[CoversClass(PdoJobRepository::class)]
final class JobRunnerTest extends DatabaseTestCase
{
    private PdoJobRepository $jobs;

    private FrozenClock $clock;

    private DateTimeImmutable $start;

    protected function setUp(): void
    {
        parent::setUp();

        // Die Auftragstabelle stempelt available_at beim Einreihen selbst — wie
        // alle Repositories in diesem Projekt. Die Testuhr muss deshalb an der
        // echten Zeit haengen, sonst liegt jeder Auftrag in der Zukunft.
        $this->start = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->clock = new FrozenClock($this->start);
        $this->jobs = new PdoJobRepository($this->database);
    }

    public function testErledigtEinenAuftrag(): void
    {
        $id = $this->jobs->enqueue('test.ok', ['zahl' => 7]);

        $bilanz = $this->runner(new RecordingHandler('test.ok'))->run();

        self::assertSame(['erledigt' => 1, 'fehlgeschlagen' => 0, 'wiederholt' => 0], $bilanz);

        $job = $this->jobs->findById($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Erledigt, $job->status);
        self::assertNotNull($job->completedAt);
    }

    public function testDieNutzlastKommtBeimHandlerAn(): void
    {
        $this->jobs->enqueue('test.ok', ['frequenz' => 'taeglich']);
        $handler = new RecordingHandler('test.ok');

        $this->runner($handler)->run();

        self::assertCount(1, $handler->handled);
        self::assertSame('taeglich', $handler->handled[0]->string('frequenz'));
    }

    public function testEinFehlerHaeltDenWorkerNichtAn(): void
    {
        $this->jobs->enqueue('test.kaputt');
        $this->jobs->enqueue('test.ok');

        $bilanz = $this->runner(new RecordingHandler('test.ok'), new FailingHandler('test.kaputt'))->run();

        // Der kaputte Auftrag wird wiederholt, der gesunde trotzdem erledigt.
        self::assertSame(1, $bilanz['erledigt']);
        self::assertSame(1, $bilanz['wiederholt']);
    }

    public function testWiederholtMitAbstandBisDieVersucheAufgebrauchtSind(): void
    {
        $id = $this->jobs->enqueue('test.kaputt', [], null, 'default', 2);
        $runner = $this->runner(new FailingHandler('test.kaputt'));

        self::assertSame(1, $runner->run()['wiederholt']);

        $nachErstemVersuch = $this->jobs->findById($id);
        self::assertNotNull($nachErstemVersuch);
        self::assertSame(JobStatus::Wartend, $nachErstemVersuch->status);
        self::assertNotNull($nachErstemVersuch->availableAt);
        // Sofort noch einmal laufen zu lassen darf nichts bringen: Der Auftrag
        // steht erst in einer Minute wieder an.
        self::assertGreaterThan($this->clock->now(), $nachErstemVersuch->availableAt);
        self::assertSame(['erledigt' => 0, 'fehlgeschlagen' => 0, 'wiederholt' => 0], $runner->run());

        $this->clock->travelTo($this->start->modify('+5 minutes'));
        self::assertSame(1, $runner->run()['fehlgeschlagen']);

        $endstand = $this->jobs->findById($id);
        self::assertNotNull($endstand);
        self::assertSame(JobStatus::Fehlgeschlagen, $endstand->status);
        self::assertStringContainsString('Absicht', (string) $endstand->lastError);
    }

    public function testEinUnbekannterTypWirdNichtWiederholt(): void
    {
        $id = $this->jobs->enqueue('test.gibtsnicht');

        $bilanz = $this->runner()->run();

        self::assertSame(1, $bilanz['fehlgeschlagen']);

        $job = $this->jobs->findById($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Fehlgeschlagen, $job->status);
    }

    public function testZweiWorkerBekommenNichtDenselbenAuftrag(): void
    {
        $this->jobs->enqueue('test.ok');

        $erster = $this->jobs->reserve('worker-a', $this->clock->now());
        $zweiter = $this->jobs->reserve('worker-b', $this->clock->now());

        self::assertNotNull($erster);
        self::assertNull($zweiter);
        self::assertSame('worker-a', $erster->reservedBy);
        self::assertSame(1, $erster->attempts);
    }

    public function testEinVerwaisterAuftragWirdWiederFreigegeben(): void
    {
        $id = $this->jobs->enqueue('test.ok');
        $this->jobs->reserve('abgestuerzter-worker', $this->clock->now());

        $runner = $this->runner(new RecordingHandler('test.ok'));

        // Noch innerhalb der Karenzzeit: Der Auftrag bleibt reserviert.
        self::assertSame(0, $runner->releaseStale());

        $this->clock->travelTo($this->start->modify('+1 hour'));
        self::assertSame(1, $runner->releaseStale());

        $job = $this->jobs->findById($id);
        self::assertNotNull($job);
        self::assertSame(JobStatus::Wartend, $job->status);
        self::assertNull($job->reservedBy);
    }

    public function testEinAuftragInDerZukunftWirdNochNichtGenommen(): void
    {
        $this->jobs->enqueue('test.ok', [], $this->start->modify('+3 hours'));

        self::assertNull($this->jobs->reserve('worker', $this->clock->now()));

        $this->clock->travelTo($this->start->modify('+3 hours +1 second'));
        self::assertNotNull($this->jobs->reserve('worker', $this->clock->now()));
    }

    public function testStandUndFehlerlisteFuerDasDashboard(): void
    {
        $this->jobs->enqueue('test.ok');
        $this->jobs->enqueue('test.kaputt', [], null, 'default', 1);

        $this->runner(new RecordingHandler('test.ok'), new FailingHandler('test.kaputt'))->run();

        self::assertSame(1, $this->jobs->countsByStatus()['erledigt'] ?? 0);
        self::assertSame(1, $this->jobs->countsByStatus()['fehlgeschlagen'] ?? 0);

        $fehler = $this->jobs->recentFailures();
        self::assertCount(1, $fehler);
        self::assertSame('test.kaputt', $fehler[0]->type);
    }

    public function testErledigteAuftraegeWerdenAufgeraeumtFehlgeschlageneNicht(): void
    {
        $this->jobs->enqueue('test.ok');
        $this->jobs->enqueue('test.kaputt', [], null, 'default', 1);
        $this->runner(new RecordingHandler('test.ok'), new FailingHandler('test.kaputt'))->run();

        $entfernt = $this->jobs->purgeCompleted($this->start->modify('+31 days'));

        self::assertSame(1, $entfernt);
        // Ein gescheiterter Auftrag wartet auf einen Menschen und darf nicht
        // stillschweigend verschwinden.
        self::assertCount(1, $this->jobs->recentFailures());
    }

    public function testHasPendingVerhindertDoppelteEinplanung(): void
    {
        self::assertFalse($this->jobs->hasPending('test.ok'));

        $this->jobs->enqueue('test.ok');
        self::assertTrue($this->jobs->hasPending('test.ok'));

        $this->runner(new RecordingHandler('test.ok'))->run();
        self::assertFalse($this->jobs->hasPending('test.ok'));
    }

    private function runner(JobHandler ...$handlers): JobRunner
    {
        $indiziert = [];
        foreach ($handlers as $handler) {
            $indiziert[$handler->type()] = $handler;
        }

        return new JobRunner($this->jobs, $indiziert, $this->clock, new NullLogger());
    }
}

final class RecordingHandler implements JobHandler
{
    /** @var list<Job> */
    public array $handled = [];

    public function __construct(private readonly string $type) {}

    public function type(): string
    {
        return $this->type;
    }

    public function handle(Job $job): string
    {
        $this->handled[] = $job;

        return 'ok';
    }
}

final class FailingHandler implements JobHandler
{
    public function __construct(private readonly string $type) {}

    public function type(): string
    {
        return $this->type;
    }

    public function handle(Job $job): string
    {
        throw new RuntimeException('Mit Absicht kaputt.');
    }
}
