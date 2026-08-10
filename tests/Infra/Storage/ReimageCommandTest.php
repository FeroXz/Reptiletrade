<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Storage;

use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Infra\Storage\ImagePipeline;
use Reptilienmarkt\Infra\Storage\PublicImageStorage;
use Reptilienmarkt\Tests\Support\JpegWithGps;

/**
 * bin/reimage.php als Ganzes.
 *
 * Der Befehl ist der einmalige Nachzieher fuer Bestandsbilder — und seit
 * Phase 12 die zweite Stelle, die listing_media.variant_widths fuellt. Wuerde
 * er das vergessen, blieben die Bilder zwar auf der Platte, aber ohne srcset:
 * Die Seite waere richtig und trotzdem verschwenderisch.
 *
 * Als Unterprozess, weil ein Skript keine Klasse ist, die sich einbinden liesse.
 */
#[CoversNothing]
final class ReimageCommandTest extends TestCase
{
    private string $root;

    private string $databasePath;

    /** Relativ zum Projektverzeichnis — STORAGE_PUBLIC wird so aufgeloest. */
    private string $uploadsRelative;

    private string $uploadsAbsolute;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = \dirname(__DIR__, 3);
        $kennung = bin2hex(random_bytes(6));

        $this->databasePath = sys_get_temp_dir() . '/rm-reimage-' . $kennung . '.sqlite';
        $this->uploadsRelative = 'public/uploads/rm-reimage-' . $kennung;
        $this->uploadsAbsolute = $this->root . '/' . $this->uploadsRelative;

        mkdir($this->uploadsAbsolute, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->uploadsAbsolute);

        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm'] as $datei) {
            if (is_file($datei)) {
                unlink($datei);
            }
        }

        parent::tearDown();
    }

    public function testDerNachzieherFuelltDieBreitenUndMeldetDenRest(): void
    {
        $this->migrate();

        $pfad = 'anzeigen/1/bestandsbild.webp';
        $this->bestandsbild($pfad);
        $mediaId = $this->insertMedia($pfad);
        // Ein Bild, dessen Datei fehlt: Es darf den Lauf nicht aufhalten und
        // behaelt seine leeren Breiten.
        $ohneDatei = $this->insertMedia('anzeigen/1/verschwunden.webp');

        [$ausgabe, $status] = $this->fuehreAus('bin/reimage.php');

        self::assertSame(0, $status, $ausgabe);
        self::assertFileExists($this->uploadsAbsolute . '/anzeigen/1/bestandsbild-400.webp');
        self::assertFileExists($this->uploadsAbsolute . '/anzeigen/1/bestandsbild-800.webp');

        self::assertSame('400,800,1600', $this->variantWidths($mediaId));
        self::assertNull($this->variantWidths($ohneDatei));
        self::assertStringContainsString('1 Zeilen haben noch keine Breiten', $ausgabe);
    }

    /**
     * Auch die Zeile, die nichts zu rechnen hat: Ihre Fassungen liegen laengst
     * auf der Platte, nur die Spalte gab es damals noch nicht.
     */
    public function testEineVollstaendigeZeileBekommtIhreBreitenNachgetragen(): void
    {
        $this->migrate();

        $pfad = 'anzeigen/2/vollstaendig.webp';
        $this->bestandsbild($pfad);
        // Alle Fassungen vorab erzeugen — der Lauf findet nichts zu tun.
        (new ImagePipeline())->processVariants(
            $this->uploadsAbsolute . '/' . $pfad,
            [400 => $this->uploadsAbsolute . '/' . PublicImageStorage::variantFor($pfad, 400),
                800 => $this->uploadsAbsolute . '/' . PublicImageStorage::variantFor($pfad, 800)],
        );
        $mediaId = $this->insertMedia($pfad);

        [$ausgabe, $status] = $this->fuehreAus('bin/reimage.php');

        self::assertSame(0, $status, $ausgabe);
        self::assertStringContainsString('1 vollstaendig', $ausgabe);
        self::assertSame('400,800,1600', $this->variantWidths($mediaId));
        self::assertStringContainsString('Alle Zeilen haben ihre Breiten', $ausgabe);
    }

    public function testDerPruefLaufSchreibtNichts(): void
    {
        $this->migrate();

        $pfad = 'anzeigen/3/nur-schauen.webp';
        $this->bestandsbild($pfad);
        $mediaId = $this->insertMedia($pfad);

        [$ausgabe, $status] = $this->fuehreAus('bin/reimage.php', '--pruefen');

        self::assertSame(0, $status, $ausgabe);
        self::assertNull($this->variantWidths($mediaId));
        self::assertFileDoesNotExist($this->uploadsAbsolute . '/anzeigen/3/nur-schauen-400.webp');
    }

    /**
     * Ein Bestandsbild: nur die grosse Fassung, wie vor Phase 11.
     */
    private function bestandsbild(string $relativePath): void
    {
        $quelle = sys_get_temp_dir() . '/rm-reimage-quelle-' . bin2hex(random_bytes(4)) . '.jpg';
        JpegWithGps::create($quelle);

        (new ImagePipeline())->processVariants($quelle, [1600 => $this->uploadsAbsolute . '/' . $relativePath]);

        unlink($quelle);
    }

    private function insertMedia(string $path): int
    {
        $pdo = $this->pdo();
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $pdo->exec(
            "INSERT INTO users (email, email_canonical, password_hash, display_name, created_at, updated_at)
             SELECT 'bild@example.tld', 'bild@example.tld', 'x', 'Testnutzer', '{$now}', '{$now}'
              WHERE NOT EXISTS (SELECT 1 FROM users)",
        );
        $pdo->exec(
            "INSERT INTO species (scientific_name, common_name_de, bnatschg_status, slug, created_at, updated_at)
             SELECT 'Pogona vitticeps', 'Bartagame', 'nicht_geschuetzt', 'pogona-vitticeps', '{$now}', '{$now}'
              WHERE NOT EXISTS (SELECT 1 FROM species)",
        );
        $pdo->exec(
            "INSERT INTO listings (user_id, type, species_id, title, status, created_at, updated_at)
             SELECT (SELECT MIN(id) FROM users), 'verkauf', (SELECT MIN(id) FROM species), 'Testanzeige', 'aktiv',
                    '{$now}', '{$now}'
              WHERE NOT EXISTS (SELECT 1 FROM listings)",
        );

        $statement = $pdo->prepare(
            "INSERT INTO listing_media (listing_id, media_type, path, sort_order, is_primary, created_at)
             VALUES ((SELECT MIN(id) FROM listings), 'bild', :pfad, 0, 0, :now)",
        );
        $statement->execute(['pfad' => $path, 'now' => $now]);

        return (int) $pdo->lastInsertId();
    }

    private function variantWidths(int $mediaId): ?string
    {
        $statement = $this->pdo()->prepare('SELECT variant_widths FROM listing_media WHERE id = :id');
        $statement->execute(['id' => $mediaId]);
        $wert = $statement->fetchColumn();

        return \is_string($wert) ? $wert : null;
    }

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function migrate(): void
    {
        [$ausgabe, $status] = $this->fuehreAus('bin/migrate.php', 'up');

        self::assertSame(0, $status, $ausgabe);
    }

    /**
     * @return array{string, int}
     */
    private function fuehreAus(string $script, string ...$argumente): array
    {
        $befehl = \sprintf(
            'DB_DATABASE=%s STORAGE_PUBLIC=%s %s %s %s 2>&1',
            escapeshellarg($this->databasePath),
            escapeshellarg($this->uploadsRelative),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($this->root . '/' . $script),
            implode(' ', array_map(escapeshellarg(...), $argumente)),
        );

        $ausgabe = [];
        $status = 0;
        exec($befehl, $ausgabe, $status);

        return [implode("\n", $ausgabe), $status];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $eintrag) {
            if ($eintrag === '.' || $eintrag === '..') {
                continue;
            }

            $voll = $path . '/' . $eintrag;
            is_dir($voll) ? $this->removeDirectory($voll) : unlink($voll);
        }

        rmdir($path);
    }
}
