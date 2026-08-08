<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Content\BlockType;
use Reptilienmarkt\Domain\Content\ContentBlock;
use Reptilienmarkt\Domain\Content\ContentException;
use Reptilienmarkt\Domain\Content\Media;
use Reptilienmarkt\Domain\Content\MediaUsageContext;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoMediaRepository;
use Reptilienmarkt\Infra\Persistence\PdoMediaUsageRepository;
use Reptilienmarkt\Infra\Storage\ImagePipeline;
use Reptilienmarkt\Infra\Storage\MediaService;
use Reptilienmarkt\Infra\Storage\MediaStorage;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;
use Reptilienmarkt\Tests\Support\JpegWithGps;

/**
 * Abnahme des Medienpakets: Metadaten nachweislich weg, Loeschsperre bei
 * Verwendung.
 */
#[CoversClass(MediaService::class)]
#[CoversClass(MediaStorage::class)]
#[CoversClass(Media::class)]
final class MediaServiceTest extends DatabaseTestCase
{
    private string $workDir = '';

    private FrozenClock $clock;

    private PdoMediaRepository $media;

    private PdoMediaUsageRepository $usages;

    private int $redakteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/rm-medien-' . bin2hex(random_bytes(6));
        mkdir($this->workDir . '/ablage', 0o775, true);

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-08T10:00:00+00:00'));
        $this->media = new PdoMediaRepository($this->database);
        $this->usages = new PdoMediaUsageRepository($this->database);
        $this->redakteur = $this->createUser('redaktion@example.tld');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);

        parent::tearDown();
    }

    // ---------------------------------------------------------- Metadaten

    public function testDasQuellbildTraegtWirklichGpsDaten(): void
    {
        // Ohne diesen Nachweis waere der eigentliche Test wertlos: Ein Bild
        // ohne EXIF enthaelt hinterher trivialerweise auch keins.
        $quelle = JpegWithGps::create($this->workDir . '/quelle.jpg');

        $exif = @exif_read_data($quelle);

        self::assertIsArray($exif);
        self::assertArrayHasKey('GPSLatitude', $exif);
    }

    public function testHochgeladeneBilderTragenKeineMetadatenMehr(): void
    {
        $quelle = JpegWithGps::create($this->workDir . '/quelle.jpg');

        $medium = $this->service()->upload($quelle, 'urlaubsfoto.jpg', $this->redakteur);

        foreach (Media::allPaths($medium->path) as $pfad) {
            $absolut = $this->workDir . '/ablage/' . $pfad;

            self::assertFileExists($absolut);

            // Kein EXIF-Block, und auch kein GPS-Rest irgendwo in den Bytes:
            // Das Bild wurde neu gezeichnet, es gibt nichts zu uebertragen.
            self::assertFalse(@exif_read_data($absolut), 'EXIF in ' . $pfad);

            $inhalt = (string) file_get_contents($absolut);
            self::assertStringNotContainsString('GPS', $inhalt);
            self::assertStringNotContainsString('Exif', $inhalt);
        }
    }

    public function testAlleDreiGroessenEntstehen(): void
    {
        $quelle = JpegWithGps::create($this->workDir . '/quelle.jpg');

        $medium = $this->service()->upload($quelle, 'bild.jpg', $this->redakteur);

        foreach (Media::WIDTHS as $kante) {
            $pfad = $this->workDir . '/ablage/' . Media::variantPath($medium->path, $kante);
            self::assertFileExists($pfad, 'Fassung ' . $kante . ' fehlt');
        }
    }

    public function testDieAblageGliedertNachJahrUndMonat(): void
    {
        $quelle = JpegWithGps::create($this->workDir . '/quelle.jpg');

        $medium = $this->service()->upload($quelle, 'bild.jpg', $this->redakteur);

        self::assertStringStartsWith('2026/08/', $medium->path);
        self::assertStringEndsWith('.webp', $medium->path);
    }

    public function testDasSrcsetNenntNurGroessenBisZumOriginal(): void
    {
        // Das Quellbild ist 600 px breit — eine 1600er-Fassung waere
        // hochskaliert und kostete Bandbreite ohne Gewinn.
        $quelle = JpegWithGps::create($this->workDir . '/quelle.jpg');
        $medium = $this->service()->upload($quelle, 'bild.jpg', $this->redakteur);

        $srcset = $medium->srcset();

        self::assertStringContainsString('400w', $srcset);
        self::assertStringNotContainsString('1600w', $srcset);
    }

    public function testDasSelbeBildZweimalErgibtEinenEintrag(): void
    {
        $quelle = JpegWithGps::create($this->workDir . '/quelle.jpg');
        $zweite = JpegWithGps::create($this->workDir . '/kopie.jpg');

        $service = $this->service();
        $erst = $service->upload($quelle, 'bild.jpg', $this->redakteur);
        $zweit = $service->upload($zweite, 'nochmal.jpg', $this->redakteur);

        self::assertSame($erst->id, $zweit->id);
        self::assertSame(1, $this->media->count());

        // Die zweite Verarbeitung darf keine verwaisten Dateien hinterlassen.
        $dateien = glob($this->workDir . '/ablage/2026/08/*.webp') ?: [];
        self::assertCount(\count(Media::WIDTHS), $dateien);
    }

    public function testEineDateiDieKeinBildIstWirdAbgewiesen(): void
    {
        file_put_contents($this->workDir . '/text.jpg', "<?php echo 'hallo';");

        $this->expectException(ContentException::class);

        $this->service()->upload($this->workDir . '/text.jpg', 'text.jpg', $this->redakteur);
    }

    // ------------------------------------------------------- Loeschsperre

    public function testEinVerwendetesBildLaesstSichNichtLoeschen(): void
    {
        $quelle = JpegWithGps::create($this->workDir . '/quelle.jpg');
        $service = $this->service();
        $medium = $service->upload($quelle, 'bild.jpg', $this->redakteur);

        $eintragId = $this->createEntry('Haltung', '/haltung/');
        $service->syncUsages($eintragId, [
            new ContentBlock(null, BlockType::Bild, 0, ['media_id' => $medium->id, 'alt_text' => 'Ein Tier']),
        ]);

        try {
            $service->delete($medium->id ?? 0, $this->redakteur, confirmed: true);
            self::fail('Das Bild haette gesperrt sein muessen.');
        } catch (ContentException $exception) {
            // Die Meldung nennt, wo es steht — sonst muesste der Redakteur suchen.
            self::assertStringContainsString('Haltung', $exception->getMessage());
        }

        self::assertNotNull($this->media->findById($medium->id ?? 0));
    }

    public function testOhneBestaetigungWirdNichtGeloescht(): void
    {
        $quelle = JpegWithGps::create($this->workDir . '/quelle.jpg');
        $medium = $this->service()->upload($quelle, 'bild.jpg', $this->redakteur);

        $this->expectException(ContentException::class);
        $this->expectExceptionMessageMatches('/Bestätigung/');

        $this->service()->delete($medium->id ?? 0, $this->redakteur);
    }

    public function testEinFreiesBildLaesstSichLoeschenUndDieDateienGehenMit(): void
    {
        $quelle = JpegWithGps::create($this->workDir . '/quelle.jpg');
        $service = $this->service();
        $medium = $service->upload($quelle, 'bild.jpg', $this->redakteur);

        $service->delete($medium->id ?? 0, $this->redakteur, confirmed: true);

        self::assertNull($this->media->findById($medium->id ?? 0));

        foreach (Media::allPaths($medium->path) as $pfad) {
            self::assertFileDoesNotExist($this->workDir . '/ablage/' . $pfad);
        }

        $aktionen = array_column(
            (new PdoAuditLog($this->database))->forEntity('media', $medium->id ?? 0),
            'action',
        );
        self::assertContains('media.deleted', $aktionen);
    }

    public function testDieSperreFaelltWennDerBlockVerschwindet(): void
    {
        $quelle = JpegWithGps::create($this->workDir . '/quelle.jpg');
        $service = $this->service();
        $medium = $service->upload($quelle, 'bild.jpg', $this->redakteur);
        $eintragId = $this->createEntry('Haltung', '/haltung/');

        $service->syncUsages($eintragId, [
            new ContentBlock(null, BlockType::Bild, 0, ['media_id' => $medium->id]),
        ]);
        self::assertTrue($this->usages->isUsed($medium->id ?? 0));

        // Block entfernt — die Verwendung wandert mit.
        $service->syncUsages($eintragId, []);
        self::assertFalse($this->usages->isUsed($medium->id ?? 0));

        $service->delete($medium->id ?? 0, $this->redakteur, confirmed: true);
        self::assertNull($this->media->findById($medium->id ?? 0));
    }

    public function testDasVorschaubildZaehltAlsVerwendung(): void
    {
        $quelle = JpegWithGps::create($this->workDir . '/quelle.jpg');
        $service = $this->service();
        $medium = $service->upload($quelle, 'bild.jpg', $this->redakteur);
        $eintragId = $this->createEntry('Haltung', '/haltung/');

        $service->syncUsages($eintragId, [], $medium->id);

        $verwendungen = $service->usagesOf($medium->id ?? 0);

        self::assertCount(1, $verwendungen);
        self::assertSame(MediaUsageContext::EntryOg, $verwendungen[0]['context']);
    }

    public function testEineGalerieMeldetJedesBild(): void
    {
        $service = $this->service();
        $erst = $service->upload(JpegWithGps::create($this->workDir . '/a.jpg', 48.1, 11.5), 'a.jpg', $this->redakteur);
        $zweit = $service->upload(JpegWithGps::create($this->workDir . '/b.jpg', 52.5, 13.4), 'b.jpg', $this->redakteur);

        $eintragId = $this->createEntry('Haltung', '/haltung/');
        $service->syncUsages($eintragId, [
            new ContentBlock(null, BlockType::Galerie, 0, ['media_ids' => [$erst->id, $zweit->id]]),
        ]);

        self::assertTrue($this->usages->isUsed($erst->id ?? 0));
        self::assertTrue($this->usages->isUsed($zweit->id ?? 0));
        self::assertSame(
            [$erst->id => 1, $zweit->id => 1],
            $this->usages->countsFor([$erst->id ?? 0, $zweit->id ?? 0]),
        );
    }

    public function testVerwendungenVerschwindenMitDemMedium(): void
    {
        $quelle = JpegWithGps::create($this->workDir . '/quelle.jpg');
        $service = $this->service();
        $medium = $service->upload($quelle, 'bild.jpg', $this->redakteur);
        $eintragId = $this->createEntry('Haltung', '/haltung/');

        $service->syncUsages($eintragId, [new ContentBlock(null, BlockType::Bild, 0, ['media_id' => $medium->id])]);
        $service->syncUsages($eintragId, []);
        $service->delete($medium->id ?? 0, $this->redakteur, confirmed: true);

        self::assertSame(0, (int) $this->database->scalar('SELECT COUNT(*) FROM media_usages'));
    }

    // -------------------------------------------------------------- Helfer

    private function createEntry(string $titel, string $pfad): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->database->execute(
            "INSERT INTO content_entries (type, slug, path, title, status, template, created_at, updated_at)
             VALUES ('seite', :slug, :pfad, :titel, 'entwurf', 'standard', :now, :now)",
            ['slug' => trim($pfad, '/'), 'pfad' => $pfad, 'titel' => $titel, 'now' => $now],
        );

        return $this->database->lastInsertId();
    }

    private function service(): MediaService
    {
        return new MediaService(
            $this->media,
            $this->usages,
            new ImagePipeline(),
            new MediaStorage($this->workDir . '/ablage'),
            new PdoAuditLog($this->database),
            $this->clock,
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $eintrag) {
            is_dir($eintrag) ? $this->removeDirectory($eintrag) : unlink($eintrag);
        }

        rmdir($directory);
    }
}
