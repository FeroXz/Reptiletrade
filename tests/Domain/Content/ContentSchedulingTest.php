<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Content;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Content\ContentPermission;
use Reptilienmarkt\Domain\Content\ContentRenderer;
use Reptilienmarkt\Domain\Content\ContentService;
use Reptilienmarkt\Domain\Content\ContentStatus;
use Reptilienmarkt\Domain\Content\ContentType;
use Reptilienmarkt\Domain\Content\MarkdownRenderer;
use Reptilienmarkt\Domain\Content\PreviewService;
use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobStatus;
use Reptilienmarkt\Domain\Privacy\RetentionPolicy;
use Reptilienmarkt\Http\Controller\ContentController;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\View\TwigFactory;
use Reptilienmarkt\Infra\Job\Handler\ContentPublishHandler;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoContentBlockRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentEditorRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentEntryRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentRevisionRepository;
use Reptilienmarkt\Infra\Persistence\PdoListingRepository;
use Reptilienmarkt\Infra\Persistence\PdoPreviewTokenRepository;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Support\Log\NullLogger;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

/**
 * Planen, Freischalten durch den Auftrag, Vorschau.
 */
#[CoversClass(ContentService::class)]
#[CoversClass(ContentPublishHandler::class)]
#[CoversClass(PreviewService::class)]
final class ContentSchedulingTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private PdoContentEntryRepository $entries;

    private PdoContentBlockRepository $blocks;

    private int $autor = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-08T10:00:00+00:00'));
        $this->entries = new PdoContentEntryRepository($this->database);
        $this->blocks = new PdoContentBlockRepository($this->database);
        $this->autor = $this->createUser('redaktion@example.tld');
    }

    public function testEinGeplanterBeitragErscheintErstNachDemJoblauf(): void
    {
        $service = $this->service();

        $beitrag = $service->create(ContentType::Beitrag, 'Neue Nachzuchten', null, null, $this->autor);
        $service->publish($beitrag->id ?? 0, $this->autor, new DateTimeImmutable('2026-08-08T12:00:00+00:00'));

        $geplant = $this->entries->findById($beitrag->id ?? 0);
        self::assertNotNull($geplant);
        self::assertSame(ContentStatus::Geplant, $geplant->status);

        // Vor dem Termin: Der Joblauf findet nichts.
        self::assertSame('Nichts faellig.', $this->handler()->handle($this->job()));
        self::assertSame(ContentStatus::Geplant, $this->zustand($beitrag->id ?? 0));

        // Der Termin ist verstrichen — aber ohne Joblauf aendert sich nichts.
        // Genau das ist der Punkt: Freigeschaltet wird nicht durch einen
        // zufaelligen Seitenaufruf.
        $this->clock->travelTo(new DateTimeImmutable('2026-08-08T12:01:00+00:00'));
        self::assertSame(ContentStatus::Geplant, $this->zustand($beitrag->id ?? 0));
        self::assertSame(404, $this->publicStatus($geplant->path));

        $bericht = $this->handler()->handle($this->job());

        self::assertStringContainsString($geplant->path, $bericht);
        self::assertSame(ContentStatus::Veroeffentlicht, $this->zustand($beitrag->id ?? 0));
        self::assertSame(200, $this->publicStatus($geplant->path));
    }

    public function testDerJoblaufIstIdempotent(): void
    {
        $service = $this->service();
        $beitrag = $service->create(ContentType::Beitrag, 'Neue Nachzuchten', null, null, $this->autor);
        $service->publish($beitrag->id ?? 0, $this->autor, new DateTimeImmutable('2026-08-08T12:00:00+00:00'));

        $this->clock->travelTo(new DateTimeImmutable('2026-08-08T12:30:00+00:00'));

        $this->handler()->handle($this->job());
        self::assertSame('Nichts faellig.', $this->handler()->handle($this->job()));
    }

    public function testDasFreischaltenGehtAlsSystemvorgangInDenAuditTrail(): void
    {
        $service = $this->service();
        $beitrag = $service->create(ContentType::Beitrag, 'Neue Nachzuchten', null, null, $this->autor);
        $service->publish($beitrag->id ?? 0, $this->autor, new DateTimeImmutable('2026-08-08T12:00:00+00:00'));

        $this->clock->travelTo(new DateTimeImmutable('2026-08-08T12:30:00+00:00'));
        $this->handler()->handle($this->job());

        // Freigeschaltet hat die Uhr, nicht ein Mensch.
        $zeile = $this->database->selectOne(
            "SELECT actor_type, actor_user_id FROM audit_log
              WHERE action = 'content.published' ORDER BY id DESC LIMIT 1",
        );

        self::assertNotNull($zeile);
        self::assertSame('system', $zeile['actor_type']);
        self::assertNull($zeile['actor_user_id']);
    }

    public function testDerBeitragspfadTraegtDasJahrDesTermins(): void
    {
        $service = $this->service();
        $beitrag = $service->create(ContentType::Beitrag, 'Rueckblick', null, null, $this->autor);

        self::assertSame('/news/2026/rueckblick/', $beitrag->path);

        $geplant = $service->publish($beitrag->id ?? 0, $this->autor, new DateTimeImmutable('2027-01-02T09:00:00+00:00'));

        self::assertSame('/news/2027/rueckblick/', $geplant->path);
    }

    // ------------------------------------------------------------ Vorschau

    public function testEinVorschaulinkZeigtDenEntwurf(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Entwurf', null, null, $this->autor);

        $token = $this->previews()->create($seite->id ?? 0, $this->autor);
        $response = $this->controller()->preview(
            (new Request('GET', '/vorschau/' . $token))->withAttributes(['token' => $token]),
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Entwurf', $response->body);
        // Ein Vorschaulink, der in einem Suchindex landet, verraet den Entwurf allen.
        self::assertSame('noindex, nofollow', $response->headers['x-robots-tag'] ?? '');
        self::assertStringContainsString('no-store', (string) ($response->headers['cache-control'] ?? ''));
    }

    public function testEinAbgelaufenerVorschaulinkZeigtNichts(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Entwurf', null, null, $this->autor);

        $token = $this->previews()->create($seite->id ?? 0, $this->autor);

        $this->clock->travelTo(new DateTimeImmutable('2026-08-09T11:00:00+00:00'));

        $response = $this->controller()->preview(
            (new Request('GET', '/vorschau/' . $token))->withAttributes(['token' => $token]),
        );

        self::assertSame(404, $response->status);
    }

    public function testEinErfundenesTokenZeigtNichts(): void
    {
        $response = $this->controller()->preview(
            (new Request('GET', '/vorschau/x'))->withAttributes(['token' => str_repeat('a', 64)]),
        );

        self::assertSame(404, $response->status);
    }

    public function testNurDerHashLiegtInDerDatenbank(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Entwurf', null, null, $this->autor);

        $token = $this->previews()->create($seite->id ?? 0, $this->autor);

        $gespeichert = $this->database->scalar('SELECT token_hash FROM content_preview_tokens');

        self::assertNotSame($token, $gespeichert);
        self::assertSame(hash('sha256', $token), $gespeichert);
    }

    public function testDerJoblaufRaeumtAbgelaufeneVorschaulinksAb(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Entwurf', null, null, $this->autor);
        $this->previews()->create($seite->id ?? 0, $this->autor);

        $this->clock->travelTo(new DateTimeImmutable('2026-08-10T10:00:00+00:00'));
        $this->handler()->handle($this->job());

        self::assertSame(0, (int) $this->database->scalar('SELECT COUNT(*) FROM content_preview_tokens'));
    }

    // -------------------------------------------------------------- Helfer

    private function zustand(int $id): ContentStatus
    {
        $entry = $this->entries->findById($id);
        self::assertNotNull($entry);

        return $entry->status;
    }

    private function publicStatus(string $pfad): int
    {
        $pfad = ltrim($pfad, '/');

        return $this->controller()->show(
            (new Request('GET', '/' . $pfad))->withAttributes(['pfad' => $pfad]),
        )->status;
    }

    private function controller(): ContentController
    {
        $root = \dirname(__DIR__, 3);

        return new ContentController(
            $this->entries,
            $this->blocks,
            new ContentRenderer(
                new MarkdownRenderer(),
                new PdoListingRepository($this->database),
                new PdoSpeciesRepository($this->database),
            ),
            $this->previews(),
            TwigFactory::create($root . '/templates', true, null, new Translator($root . '/lang')),
        );
    }

    private function previews(): PreviewService
    {
        return new PreviewService(new PdoPreviewTokenRepository($this->database), $this->clock);
    }

    private function handler(): ContentPublishHandler
    {
        return new ContentPublishHandler($this->service(), $this->previews(), $this->clock, new NullLogger());
    }

    private function service(): ContentService
    {
        return new ContentService(
            $this->entries,
            $this->blocks,
            new PdoContentRevisionRepository($this->database),
            new RetentionPolicy(require \dirname(__DIR__, 3) . '/config/aufbewahrung.php', $this->clock),
            new PdoAuditLog($this->database),
            $this->clock,
        );
    }

    private function job(): Job
    {
        return new Job(1, 'content.publish', [], 'default', JobStatus::Laeuft);
    }

    /**
     * Nur damit die Berechtigungspruefung im selben Paket mitlaeuft — sie
     * entscheidet, wer planen darf.
     */
    public function testDieBerechtigungBleibtAmRedaktionsrecht(): void
    {
        self::assertFalse((new ContentPermission(new PdoContentEditorRepository($this->database)))->mayEdit(null));
    }
}
