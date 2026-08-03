<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Privacy;

use DateTimeImmutable;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reptilienmarkt\Domain\Privacy\AccountDeletionService;
use Reptilienmarkt\Domain\Privacy\DataExportService;
use Reptilienmarkt\Domain\Privacy\PrivacyException;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Storage\PrivateStorage;
use Reptilienmarkt\Infra\Storage\PublicImageStorage;
use Reptilienmarkt\Support\Timestamp;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;
use SplFileInfo;

#[CoversClass(AccountDeletionService::class)]
#[CoversClass(DataExportService::class)]
final class AccountDeletionServiceTest extends DatabaseTestCase
{
    private string $publicPath;

    private string $privatePath;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $basis = sys_get_temp_dir() . '/reptilienmarkt-privacy-' . bin2hex(random_bytes(6));
        $this->publicPath = $basis . '/public';
        $this->privatePath = $basis . '/private';
        mkdir($this->publicPath, 0o775, true);
        mkdir($this->privatePath, 0o775, true);

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-03T10:00:00Z'));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory(\dirname($this->publicPath));

        parent::tearDown();
    }

    public function testOhneBewertungenWirdVollstaendigGeloescht(): void
    {
        $userId = $this->createUser('weg@example.tld');
        $artId = $this->createSpecies();
        $this->createListing($userId, $artId);

        $ergebnis = $this->service()->delete($this->user($userId), $userId);

        self::assertFalse($ergebnis->anonymized);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM users WHERE id = ' . $userId));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM listings WHERE user_id = ' . $userId));
    }

    public function testMitBewertungWirdAnonymisiertStattGeloescht(): void
    {
        $verkaeufer = $this->createUser('verkaeufer@example.tld');
        $kaeufer = $this->createUser('kaeufer@example.tld');
        $artId = $this->createSpecies();
        $anzeigeId = $this->createListing($verkaeufer, $artId);
        $this->createReview($anzeigeId, $kaeufer, $verkaeufer, 'Alles bestens gelaufen.');

        $ergebnis = $this->service()->delete($this->user($verkaeufer), $verkaeufer);

        self::assertTrue($ergebnis->anonymized);

        $konto = $this->database->selectOne('SELECT * FROM users WHERE id = :id', ['id' => $verkaeufer]);
        self::assertNotNull($konto);
        self::assertSame('Gelöschtes Konto', $konto['display_name']);
        self::assertNull($konto['phone']);
        self::assertNull($konto['postal_code']);
        self::assertSame('geloescht', $konto['status']);
        self::assertStringNotContainsString('verkaeufer@example.tld', (string) $konto['email']);

        // Die Bewertung des Kaeufers gehoert auch ihm — sie bleibt.
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM reviews WHERE to_user_id = ' . $verkaeufer));
        self::assertSame(
            'Alles bestens gelaufen.',
            $this->database->scalar('SELECT comment FROM reviews WHERE to_user_id = :id', ['id' => $verkaeufer]),
        );

        // Die Anzeigen sind abgeschaltet, nicht geloescht: Die Bewertung
        // verweist auf sie.
        self::assertSame('gesperrt', $this->database->scalar(
            'SELECT status FROM listings WHERE id = :id',
            ['id' => $anzeigeId],
        ));
    }

    public function testAuchEineAbgegebeneBewertungFuehrtZurAnonymisierung(): void
    {
        $kaeufer = $this->createUser('kaeufer@example.tld');
        $verkaeufer = $this->createUser('verkaeufer@example.tld');
        $artId = $this->createSpecies();
        $anzeigeId = $this->createListing($verkaeufer, $artId);
        $this->createReview($anzeigeId, $kaeufer, $verkaeufer, 'Gerne wieder.');

        $vorschau = $this->service()->preview($this->user($kaeufer));
        self::assertTrue($vorschau->willAnonymize);
        self::assertSame(1, $vorschau->reviewsGiven);

        $this->service()->delete($this->user($kaeufer), $kaeufer);

        // Der Wortlaut faellt weg, die Wertung bleibt — sonst verschoebe sich
        // der Schnitt des Verkaeufers.
        self::assertNull($this->database->scalar('SELECT comment FROM reviews WHERE from_user_id = :id', ['id' => $kaeufer]));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM reviews WHERE from_user_id = ' . $kaeufer));
    }

    public function testEinLaufendesAboVerhindertDieLoeschung(): void
    {
        $userId = $this->createUser('abonnent@example.tld');
        $this->database->execute(
            "INSERT INTO subscriptions (user_id, plan_key, status, current_period_start, current_period_end,
                                        created_at, updated_at)
             VALUES (:id, 'zuechter', 'aktiv', :now, :ende, :now, :now)",
            ['id' => $userId, 'now' => Timestamp::now(), 'ende' => Timestamp::utc($this->clock->now()->modify('+30 days'))],
        );

        $this->expectException(PrivacyException::class);
        $this->expectExceptionMessageMatches('/Mitgliedschaft/');

        $this->service()->delete($this->user($userId), $userId);
    }

    public function testDateienVerschwindenVonDerPlatte(): void
    {
        $userId = $this->createUser('bilder@example.tld');
        $artId = $this->createSpecies();
        $anzeigeId = $this->createListing($userId, $artId);

        $bildPfad = 'anzeigen/1/bild.webp';
        mkdir($this->publicPath . '/anzeigen/1', 0o775, true);
        file_put_contents($this->publicPath . '/' . $bildPfad, 'bild');

        $this->database->execute(
            "INSERT INTO listing_media (listing_id, media_type, path, sort_order, created_at)
             VALUES (:listing, 'bild', :path, 0, :now)",
            ['listing' => $anzeigeId, 'path' => $bildPfad, 'now' => Timestamp::now()],
        );

        $ergebnis = $this->service()->delete($this->user($userId), $userId);

        self::assertSame(1, $ergebnis->filesRemoved);
        self::assertFileDoesNotExist($this->publicPath . '/' . $bildPfad);
    }

    public function testDieAuskunftEnthaeltKeineZugangsmittel(): void
    {
        $userId = $this->createUser('auskunft@example.tld');
        $artId = $this->createSpecies();
        $this->createListing($userId, $artId);
        $this->database->execute(
            "UPDATE users SET totp_secret = 'GEHEIMESTOTPSECRET', phone = '+49 170 1234567' WHERE id = :id",
            ['id' => $userId],
        );

        $export = new DataExportService($this->database, new PdoAuditLog($this->database), $this->clock);
        $json = $export->toJson($this->user($userId));

        self::assertStringNotContainsString('GEHEIMESTOTPSECRET', $json);
        self::assertStringNotContainsString('argon2id$dummy', $json);

        /** @var array<string, mixed> $daten */
        $daten = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('konto', $daten);
        self::assertArrayHasKey('anzeigen', $daten);
        // Die eigene Telefonnummer ist eine Angabe ueber die Person und gehoert
        // in die Auskunft.
        self::assertStringContainsString('+49 170 1234567', $json);
        self::assertStringContainsString('auskunft@example.tld', $json);
    }

    public function testDieAuskunftNenntDenDateinamenMitKontoUndDatum(): void
    {
        $userId = $this->createUser('name@example.tld');
        $export = new DataExportService($this->database, new PdoAuditLog($this->database), $this->clock);

        self::assertSame(
            \sprintf('reptilienmarkt-auskunft-%d-2026-08-03.json', $userId),
            $export->filename($this->user($userId)),
        );
    }

    private function service(): AccountDeletionService
    {
        return new AccountDeletionService(
            $this->database,
            new PublicImageStorage($this->publicPath),
            new PrivateStorage($this->privatePath),
            new PdoAuditLog($this->database),
            $this->clock,
        );
    }

    private function user(int $id): User
    {
        $row = $this->database->selectOne('SELECT email, display_name FROM users WHERE id = :id', ['id' => $id]);

        return new User($id, (string) ($row['email'] ?? ''), (string) ($row['display_name'] ?? ''));
    }

    private function createReview(int $listingId, int $from, int $to, string $comment): void
    {
        $now = Timestamp::now();

        $this->database->execute(
            'INSERT INTO reviews (listing_id, from_user_id, to_user_id, rating, comment, deal_confirmed_at, created_at)
             VALUES (:listing, :from, :to, 5, :comment, :now, :now)',
            ['listing' => $listingId, 'from' => $from, 'to' => $to, 'comment' => $comment, 'now' => $now],
        );
    }

    private function scalar(string $sql): int
    {
        $value = $this->database->scalar($sql);

        return (int) (is_numeric($value) ? $value : 0);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $eintraege = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($eintraege as $eintrag) {
            if ($eintrag instanceof SplFileInfo) {
                $eintrag->isDir() ? rmdir($eintrag->getPathname()) : unlink($eintrag->getPathname());
            }
        }

        rmdir($path);
    }
}
