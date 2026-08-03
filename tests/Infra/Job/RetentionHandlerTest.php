<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Job;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Privacy\RetentionConfigurationException;
use Reptilienmarkt\Domain\Privacy\RetentionPolicy;
use Reptilienmarkt\Infra\Job\Handler\RetentionHandler;
use Reptilienmarkt\Infra\Storage\PrivateStorage;
use Reptilienmarkt\Support\Log\NullLogger;
use Reptilienmarkt\Support\Timestamp;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(RetentionHandler::class)]
#[CoversClass(RetentionPolicy::class)]
final class RetentionHandlerTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private string $privatePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-03T02:00:00Z'));
        $this->privatePath = sys_get_temp_dir() . '/reptilienmarkt-retention-' . bin2hex(random_bytes(6));
        mkdir($this->privatePath, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->privatePath . '/*') ?: [] as $datei) {
            unlink($datei);
        }
        rmdir($this->privatePath);

        parent::tearDown();
    }

    public function testAlteGespraecheVerschwindenMitsamtNachrichten(): void
    {
        $verkaeufer = $this->createUser('verkaeufer@example.tld');
        $kaeufer = $this->createUser('kaeufer@example.tld');
        $art = $this->createSpecies();

        // Ein Gespraech je Anzeige und Kaeufer — der eindeutige Index laesst
        // kein zweites zu.
        $alt = $this->conversation($this->createListing($verkaeufer, $art), $kaeufer, $verkaeufer, $this->vorTagen(800));
        $frisch = $this->conversation($this->createListing($verkaeufer, $art), $kaeufer, $verkaeufer, $this->vorTagen(10));

        $this->handler(['nachrichten_tage' => 730])->handle($this->job());

        self::assertSame(0, $this->anzahl('SELECT COUNT(*) FROM conversations WHERE id = ' . $alt));
        self::assertSame(0, $this->anzahl('SELECT COUNT(*) FROM messages WHERE conversation_id = ' . $alt));
        self::assertSame(1, $this->anzahl('SELECT COUNT(*) FROM conversations WHERE id = ' . $frisch));
        self::assertSame(1, $this->anzahl('SELECT COUNT(*) FROM messages WHERE conversation_id = ' . $frisch));
    }

    public function testFristNullSchaltetDieLoeschungAb(): void
    {
        $verkaeufer = $this->createUser('verkaeufer@example.tld');
        $kaeufer = $this->createUser('kaeufer@example.tld');
        $anzeige = $this->createListing($verkaeufer, $this->createSpecies());
        $this->conversation($anzeige, $kaeufer, $verkaeufer, $this->vorTagen(5000));

        $bericht = $this->handler(['nachrichten_tage' => 0])->handle($this->job());

        self::assertSame('nichts zu löschen', $bericht);
        self::assertSame(1, $this->anzahl('SELECT COUNT(*) FROM conversations'));
    }

    public function testIdentitaetsnachweiseVerschwindenMitDerDatei(): void
    {
        $userId = $this->createUser('nachweis@example.tld');
        file_put_contents($this->privatePath . '/ausweis.pdf', 'inhalt');

        $this->database->execute(
            "INSERT INTO user_documents (user_id, doc_type, private_path, original_filename, mime_type, byte_size,
                                         status, reviewed_at, created_at)
             VALUES (:id, 'ausweis', 'ausweis.pdf', 'ausweis.pdf', 'application/pdf', 6,
                     'geprueft', :geprueft, :geprueft)",
            ['id' => $userId, 'geprueft' => $this->vorTagen(60)],
        );

        $this->handler(['identitaetsnachweise_tage' => 30])->handle($this->job());

        self::assertSame(0, $this->anzahl('SELECT COUNT(*) FROM user_documents'));
        self::assertFileDoesNotExist($this->privatePath . '/ausweis.pdf');
    }

    public function testEinNochOffenerNachweisBleibtLiegen(): void
    {
        $userId = $this->createUser('offen@example.tld');
        file_put_contents($this->privatePath . '/offen.pdf', 'inhalt');

        $this->database->execute(
            "INSERT INTO user_documents (user_id, doc_type, private_path, original_filename, mime_type, byte_size,
                                         status, created_at)
             VALUES (:id, 'ausweis', 'offen.pdf', 'offen.pdf', 'application/pdf', 6, 'offen', :alt)",
            ['id' => $userId, 'alt' => $this->vorTagen(400)],
        );

        // Ungeprueft heisst: Der Zweck ist noch nicht erfuellt. Die Frist
        // laeuft erst ab der Pruefung.
        $this->handler(['identitaetsnachweise_tage' => 30])->handle($this->job());

        self::assertSame(1, $this->anzahl('SELECT COUNT(*) FROM user_documents'));
        self::assertFileExists($this->privatePath . '/offen.pdf');
    }

    public function testRechtsnachweiseVerlierenDieDateiBehaltenAberDenBeleg(): void
    {
        $userId = $this->createUser('legal@example.tld');
        $anzeige = $this->createListing($userId, $this->createSpecies());
        file_put_contents($this->privatePath . '/cites.pdf', 'inhalt');

        $this->database->execute(
            "INSERT INTO legal_docs (listing_id, doc_type, reference_number, private_path, original_filename,
                                     mime_type, byte_size, created_at, updated_at)
             VALUES (:anzeige, 'eu_bescheinigung', 'DE-2016-0815', 'cites.pdf', 'cites.pdf',
                     'application/pdf', 6, :alt, :alt)",
            ['anzeige' => $anzeige, 'alt' => $this->vorTagen(4000)],
        );

        $this->handler(['rechtsnachweise_tage' => 3650])->handle($this->job());

        $zeile = $this->database->selectOne('SELECT * FROM legal_docs');
        self::assertNotNull($zeile);
        self::assertNull($zeile['private_path']);
        // Dass es den Nachweis gab, bleibt belegt — nur die Datei ist weg.
        self::assertSame('DE-2016-0815', $zeile['reference_number']);
        self::assertFileDoesNotExist($this->privatePath . '/cites.pdf');
    }

    public function testEineAnzeigeMitBewertungWirdNichtGeloescht(): void
    {
        $verkaeufer = $this->createUser('verkaeufer@example.tld');
        $kaeufer = $this->createUser('kaeufer@example.tld');
        $art = $this->createSpecies();

        $mitBewertung = $this->createListing($verkaeufer, $art, 'verkauft');
        $ohneBewertung = $this->createListing($verkaeufer, $art, 'abgelaufen');
        $this->database->execute(
            'UPDATE listings SET updated_at = :alt WHERE id IN (:a, :b)',
            ['alt' => $this->vorTagen(400), 'a' => $mitBewertung, 'b' => $ohneBewertung],
        );
        $this->database->execute(
            'INSERT INTO reviews (listing_id, from_user_id, to_user_id, rating, deal_confirmed_at, created_at)
             VALUES (:anzeige, :von, :an, 5, :now, :now)',
            ['anzeige' => $mitBewertung, 'von' => $kaeufer, 'an' => $verkaeufer, 'now' => Timestamp::now()],
        );

        $this->handler(['anzeigen_tage' => 365])->handle($this->job());

        self::assertSame(1, $this->anzahl('SELECT COUNT(*) FROM listings WHERE id = ' . $mitBewertung));
        self::assertSame(0, $this->anzahl('SELECT COUNT(*) FROM listings WHERE id = ' . $ohneBewertung));
    }

    public function testDerAuditTrailWirdNieAngefasst(): void
    {
        $this->database->execute(
            "INSERT INTO audit_log (occurred_at, actor_type, action, entity_type, entity_id, data_json)
             VALUES (:alt, 'system', 'legal.rule_applied', 'listing', 1, '{}')",
            ['alt' => $this->vorTagen(5000)],
        );

        $this->handler([
            'nachrichten_tage' => 1,
            'identitaetsnachweise_tage' => 1,
            'rechtsnachweise_tage' => 1,
            'anzeigen_tage' => 1,
            'jobs_tage' => 1,
            'rate_limits_tage' => 1,
            'token_tage' => 1,
            'sitzungen_tage' => 1,
        ])->handle($this->job());

        // Er ist kein Protokoll, sondern ein Nachweis.
        self::assertSame(1, $this->anzahl('SELECT COUNT(*) FROM audit_log'));
    }

    public function testEineFehlendeFristIstEinKonfigurationsfehler(): void
    {
        $policy = new RetentionPolicy([], $this->clock);

        $this->expectException(RetentionConfigurationException::class);
        $this->expectExceptionMessageMatches('/aufbewahrung\.php/');

        $policy->days('nachrichten_tage');
    }

    public function testErinnerungstageKommenAbsteigend(): void
    {
        $policy = new RetentionPolicy(['ablauf_erinnerung_tage' => [1, 7]], $this->clock);

        self::assertSame([7, 1], $policy->reminderDays());
    }

    public function testDieAusgelieferteKonfigurationIstVollstaendig(): void
    {
        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 3) . '/config/aufbewahrung.php';
        $policy = new RetentionPolicy($config, $this->clock);

        foreach ([
            'nachrichten_tage', 'rechtsnachweise_tage', 'identitaetsnachweise_tage', 'anzeigen_tage',
            'jobs_tage', 'rate_limits_tage', 'token_tage', 'sitzungen_tage', 'protokoll_tage',
        ] as $schluessel) {
            self::assertGreaterThanOrEqual(0, $policy->days($schluessel), $schluessel);
        }

        // Rechtsnachweise muessen laenger liegen als Identitaetsnachweise: Sie
        // belegen im Streitfall die Rechtmaessigkeit einer Abgabe.
        self::assertGreaterThan($policy->days('identitaetsnachweise_tage'), $policy->days('rechtsnachweise_tage'));
        self::assertNotSame([], $policy->reminderDays());
    }

    /**
     * @param array<string, int> $fristen
     */
    private function handler(array $fristen): RetentionHandler
    {
        // Nicht genannte Fristen stehen auf 0 und sind damit abgeschaltet — so
        // prueft jeder Test genau einen Bereich.
        $vollstaendig = array_merge([
            'nachrichten_tage' => 0,
            'rechtsnachweise_tage' => 0,
            'identitaetsnachweise_tage' => 0,
            'anzeigen_tage' => 0,
            'jobs_tage' => 0,
            'rate_limits_tage' => 0,
            'token_tage' => 0,
            'sitzungen_tage' => 0,
        ], $fristen);

        return new RetentionHandler(
            $this->database,
            new RetentionPolicy($vollstaendig, $this->clock),
            new PrivateStorage($this->privatePath),
            new NullLogger(),
        );
    }

    private function job(): Job
    {
        return new Job(1, 'retention.enforce');
    }

    private function conversation(int $listingId, int $buyerId, int $sellerId, string $letzteNachricht): int
    {
        $this->database->execute(
            'INSERT INTO conversations (listing_id, buyer_id, seller_id, message_count, created_at, last_message_at)
             VALUES (:listing, :buyer, :seller, 1, :now, :letzte)',
            [
                'listing' => $listingId,
                'buyer' => $buyerId,
                'seller' => $sellerId,
                'now' => $letzteNachricht,
                'letzte' => $letzteNachricht,
            ],
        );

        $id = $this->database->lastInsertId();

        $this->database->execute(
            'INSERT INTO messages (conversation_id, sender_id, sequence, body, created_at)
             VALUES (:gespraech, :sender, 1, :body, :now)',
            ['gespraech' => $id, 'sender' => $buyerId, 'body' => 'Hallo', 'now' => $letzteNachricht],
        );

        return $id;
    }

    private function vorTagen(int $tage): string
    {
        return Timestamp::utc($this->clock->now()->modify(\sprintf('-%d days', $tage)));
    }

    private function anzahl(string $sql): int
    {
        $value = $this->database->scalar($sql);

        return (int) (is_numeric($value) ? $value : 0);
    }
}
