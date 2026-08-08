<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Content;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Content\BlockType;
use Reptilienmarkt\Domain\Content\ContentBlock;
use Reptilienmarkt\Domain\Content\ContentException;
use Reptilienmarkt\Domain\Content\ContentService;
use Reptilienmarkt\Domain\Content\ContentStatus;
use Reptilienmarkt\Domain\Content\ContentType;
use Reptilienmarkt\Domain\Privacy\RetentionConfigurationException;
use Reptilienmarkt\Domain\Privacy\RetentionPolicy;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoContentBlockRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentEntryRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentRevisionRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(ContentService::class)]
#[CoversClass(PdoContentRevisionRepository::class)]
final class ContentRevisionTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private PdoContentEntryRepository $entries;

    private PdoContentBlockRepository $blocks;

    private PdoContentRevisionRepository $revisions;

    private int $autor = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-08T10:00:00+00:00'));
        $this->entries = new PdoContentEntryRepository($this->database);
        $this->blocks = new PdoContentBlockRepository($this->database);
        $this->revisions = new PdoContentRevisionRepository($this->database);
        $this->autor = $this->createUser('redaktion@example.tld');
    }

    public function testJedesSpeichernLegtEineFassungAn(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Haltung', null, null, $this->autor);
        $id = $seite->id ?? 0;

        // Die Anlage selbst ist schon Fassung 1.
        self::assertSame(1, $this->revisions->count($id));

        $service->saveBlocks($id, [new ContentBlock(null, BlockType::Text, 0, ['text' => 'Erster Stand'])], $this->autor);
        $service->publish($id, $this->autor);

        self::assertSame(3, $this->revisions->count($id));

        $fassungen = $this->revisions->forEntry($id);
        self::assertSame('Veroeffentlicht', $fassungen[0]->comment);
        self::assertSame(3, $fassungen[0]->revisionNo);
    }

    public function testEineFassungTraegtKopfUndBloecke(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Haltung', null, null, $this->autor);
        $id = $seite->id ?? 0;

        $service->saveBlocks($id, [
            new ContentBlock(null, BlockType::Text, 0, ['text' => 'Erster Stand']),
            new ContentBlock(null, BlockType::Trenner, 1, []),
        ], $this->autor);

        $fassung = $this->revisions->forEntry($id)[0];

        self::assertSame('Haltung', $fassung->title());
        self::assertCount(2, $fassung->blocks());
        self::assertSame('text', $fassung->blocks()[0]['type']);
    }

    public function testZuruecksetzenStelltTitelUndBloeckeWiederHer(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Haltung', null, null, $this->autor);
        $id = $seite->id ?? 0;

        $service->saveBlocks($id, [new ContentBlock(null, BlockType::Text, 0, ['text' => 'Guter Stand'])], $this->autor);
        $gut = $this->revisions->forEntry($id)[0]->revisionNo;

        $service->updateHeader($id, ['titel' => 'Versehentlich umbenannt'], $this->autor);
        $service->saveBlocks($id, [new ContentBlock(null, BlockType::Text, 0, ['text' => 'Kaputt'])], $this->autor);

        $service->restore($id, $gut, $this->autor);

        $entry = $this->entries->findById($id);
        self::assertNotNull($entry);
        self::assertSame('Haltung', $entry->title);
        self::assertSame('Guter Stand', $this->blocks->forEntry($id)[0]->string('text'));
    }

    public function testZuruecksetzenLaesstPfadUndStatusUnberuehrt(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Haltung', null, null, $this->autor);
        $id = $seite->id ?? 0;

        $alteFassung = $this->revisions->forEntry($id)[0]->revisionNo;

        $service->publish($id, $this->autor);
        $service->updateHeader($id, ['slug' => 'neue-adresse'], $this->autor);

        $service->restore($id, $alteFassung, $this->autor);

        $entry = $this->entries->findById($id);
        self::assertNotNull($entry);
        // Eine alte Fassung zurueckzuspielen ist eine Aussage ueber den Inhalt,
        // nicht darueber, wo er liegt oder ob er online ist.
        self::assertSame('/neue-adresse/', $entry->path);
        self::assertSame(ContentStatus::Veroeffentlicht, $entry->status);
    }

    public function testDerStandVorDemZuruecksetzenBleibtErhalten(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Haltung', null, null, $this->autor);
        $id = $seite->id ?? 0;

        $erste = $this->revisions->forEntry($id)[0]->revisionNo;
        $service->updateHeader($id, ['titel' => 'Zweiter Titel'], $this->autor);
        $service->saveBlocks($id, [new ContentBlock(null, BlockType::Text, 0, ['text' => 'Zweiter Stand'])], $this->autor);

        $service->restore($id, $erste, $this->autor);

        // Sonst waere das Zuruecksetzen der einzige Schritt ohne Rueckweg.
        $kommentare = array_map(
            static fn($fassung): string => $fassung->comment,
            $this->revisions->forEntry($id),
        );

        self::assertStringContainsString('Vor dem Zuruecksetzen', $kommentare[0]);
    }

    public function testZuruecksetzenProtokolliert(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Haltung', null, null, $this->autor);
        $id = $seite->id ?? 0;

        $service->restore($id, 1, $this->autor);

        $aktionen = array_column((new PdoAuditLog($this->database))->forEntity('content_entry', $id), 'action');

        self::assertContains('content.restored', $aktionen);
    }

    public function testEineUnbekannteFassungWirdAbgewiesen(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Haltung', null, null, $this->autor);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessageMatches('/Fassung 99/');

        $service->restore($seite->id ?? 0, 99, $this->autor);
    }

    public function testNurDieJuengstenFassungenBleibenLiegen(): void
    {
        $service = $this->service(behalten: 3);
        $seite = $service->create(ContentType::Seite, 'Haltung', null, null, $this->autor);
        $id = $seite->id ?? 0;

        for ($i = 0; $i < 6; ++$i) {
            $service->saveBlocks($id, [new ContentBlock(null, BlockType::Text, 0, ['text' => 'Stand ' . $i])], $this->autor);
        }

        self::assertSame(3, $this->revisions->count($id));

        // Weggeraeumt werden die aeltesten, nicht irgendwelche.
        $nummern = array_map(static fn($f): int => $f->revisionNo, $this->revisions->forEntry($id));
        self::assertSame([7, 6, 5], $nummern);
    }

    public function testEineNullInDerAufbewahrungWirdLautAbgewiesen(): void
    {
        // Sie waere kein Abschalten, sondern der Verlust jeder
        // Rueckkehrmoeglichkeit — und das darf nicht still passieren.
        $this->expectException(RetentionConfigurationException::class);

        (new RetentionPolicy(['inhalt_fassungen_je_eintrag' => 0], $this->clock))->contentRevisions();
    }

    public function testFassungenVerschwindenMitDemEintrag(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Haltung', null, null, $this->autor);

        $service->delete($seite->id ?? 0, $this->autor);

        self::assertSame(0, (int) $this->database->scalar('SELECT COUNT(*) FROM content_revisions'));
    }

    private function service(int $behalten = 30): ContentService
    {
        return new ContentService(
            $this->entries,
            $this->blocks,
            $this->revisions,
            new RetentionPolicy(['inhalt_fassungen_je_eintrag' => $behalten], $this->clock),
            new PdoAuditLog($this->database),
            $this->clock,
        );
    }
}
