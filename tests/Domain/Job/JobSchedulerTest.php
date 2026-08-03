<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Job;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Job\JobException;
use Reptilienmarkt\Domain\Job\JobScheduler;
use Reptilienmarkt\Infra\Persistence\PdoJobRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(JobScheduler::class)]
final class JobSchedulerTest extends DatabaseTestCase
{
    private PdoJobRepository $jobs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobs = new PdoJobRepository($this->database);
    }

    public function testStuendlichesLaeuftZuJederStunde(): void
    {
        $eingeplant = $this->scheduler('2026-08-03T13:00:00Z')->schedule();

        self::assertContains('listing.archive', $eingeplant);
        self::assertContains('billing.expire', $eingeplant);
        // Die Nachtaufgaben nicht.
        self::assertNotContains('retention.enforce', $eingeplant);
    }

    public function testNaechtlichesLaeuftNurZurEigenenStunde(): void
    {
        // 3 Uhr UTC — die Aufbewahrungsfristen.
        $eingeplant = $this->scheduler('2026-08-03T03:30:00Z')->schedule();

        self::assertContains('retention.enforce', $eingeplant);
        self::assertNotContains('media.cleanup', $eingeplant);
    }

    public function testEinNochOffenerAuftragWirdNichtDoppeltEingeplant(): void
    {
        $planer = $this->scheduler('2026-08-03T13:00:00Z');

        self::assertContains('listing.archive', $planer->schedule());
        // Ein haengender Job soll sich nicht zu einem Stapel auswachsen.
        self::assertNotContains('listing.archive', $planer->schedule());
        self::assertSame(1, $this->anzahl("SELECT COUNT(*) FROM jobs WHERE type = 'listing.archive'"));
    }

    public function testDieSuchbenachrichtigungBekommtIhreFrequenz(): void
    {
        $this->scheduler('2026-08-03T07:10:00Z')->schedule();

        $payload = $this->database->scalar("SELECT payload_json FROM jobs WHERE type = 'saved_search.alert'");

        self::assertSame('{"frequenz":"taeglich"}', $payload);
    }

    public function testDerPlanWirdInUtcGelesen(): void
    {
        // 5:30 Uhr Berlin im Sommer ist 3:30 UTC — die Fristen laufen also,
        // obwohl die lokale Uhr eine andere Stunde zeigt.
        $planer = $this->scheduler('2026-08-03T05:30:00+02:00');

        self::assertContains('retention.enforce', $planer->schedule());
    }

    public function testEinUnbekannterTypLaesstSichNichtVonHandEinplanen(): void
    {
        $this->expectException(JobException::class);

        $this->scheduler('2026-08-03T13:00:00Z')->enqueueOnce('gibt.es.nicht');
    }

    public function testDieNeuindizierungLaeuftNurVonHand(): void
    {
        $planer = $this->scheduler('2026-08-03T13:00:00Z');

        self::assertNotContains('search.reindex', $planer->schedule());
        // Sie steht in keinem Zeitplan, ist aber von Hand ausloesbar.
        self::assertGreaterThan(0, $planer->enqueueOnce('search.reindex'));
    }

    public function testJederGeplanteTypHatEinenHandlerInDerVerdrahtung(): void
    {
        $planer = $this->scheduler('2026-08-03T13:00:00Z');

        /** @var \Reptilienmarkt\Support\Container $container */
        $container = require \dirname(__DIR__, 3) . '/config/container.php';
        $runner = $container->get(\Reptilienmarkt\Domain\Job\JobRunner::class);
        $bekannt = $runner->knownTypes();

        // Ein Zeitplaneintrag ohne Handler waere ein Auftrag, der bei jedem
        // Lauf sofort und dauerhaft scheitert.
        foreach (array_keys($planer->plan()) as $typ) {
            self::assertContains($typ, $bekannt, 'Kein Handler fuer ' . $typ);
        }

        // Die Neuindizierung laeuft nur von Hand, braucht aber denselben Weg.
        self::assertContains('search.reindex', $bekannt);
    }

    private function scheduler(string $jetzt): JobScheduler
    {
        return new JobScheduler($this->jobs, new FrozenClock(new DateTimeImmutable($jetzt)));
    }

    private function anzahl(string $sql): int
    {
        $value = $this->database->scalar($sql);

        return (int) (is_numeric($value) ? $value : 0);
    }
}
