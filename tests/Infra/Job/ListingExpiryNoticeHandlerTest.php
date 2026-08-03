<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Job;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Privacy\RetentionPolicy;
use Reptilienmarkt\Infra\Job\Handler\ListingExpiryNoticeHandler;
use Reptilienmarkt\Infra\Job\Handler\MediaCleanupHandler;
use Reptilienmarkt\Support\Log\NullLogger;
use Reptilienmarkt\Support\Timestamp;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\CollectingMailer;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(ListingExpiryNoticeHandler::class)]
#[CoversClass(MediaCleanupHandler::class)]
final class ListingExpiryNoticeHandlerTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private CollectingMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-03T06:00:00Z'));
        $this->mailer = new CollectingMailer();
    }

    public function testErinnertAnT7UndT1(): void
    {
        $userId = $this->createUser('halter@example.tld');
        $art = $this->createSpecies();

        $inSiebenTagen = $this->createListing($userId, $art);
        $inEinemTag = $this->createListing($userId, $art);
        $inDreissigTagen = $this->createListing($userId, $art);

        $this->setzeAblauf($inSiebenTagen, 7);
        $this->setzeAblauf($inEinemTag, 1);
        $this->setzeAblauf($inDreissigTagen, 30);

        $bericht = $this->handler()->handle(new Job(1, 'listing.expiry_notice'));

        self::assertSame('2 Erinnerungen verschickt', $bericht);
        self::assertCount(2, $this->mailer->messages());

        $betreffe = array_map(static fn($mail): string => $mail->subject, $this->mailer->messages());
        self::assertContains('Deine Anzeige läuft in 7 Tagen ab', $betreffe);
        // Ein Tag ist Einzahl, nicht "in 1 Tagen".
        self::assertContains('Deine Anzeige läuft morgen ab', $betreffe);
    }

    public function testEinZweiterLaufVerschicktNichtsNochmal(): void
    {
        $userId = $this->createUser('halter@example.tld');
        $anzeige = $this->createListing($userId, $this->createSpecies());
        $this->setzeAblauf($anzeige, 7);

        $handler = $this->handler();
        $handler->handle(new Job(1, 'listing.expiry_notice'));
        $this->mailer->clear();

        // Ohne den Vermerk waere jeder Neustart des Workers eine Mailwelle.
        $handler->handle(new Job(2, 'listing.expiry_notice'));

        self::assertSame([], $this->mailer->messages());
    }

    public function testEineGesperrteAnzeigeErinnertNicht(): void
    {
        $userId = $this->createUser('halter@example.tld');
        $anzeige = $this->createListing($userId, $this->createSpecies(), 'gesperrt');
        $this->setzeAblauf($anzeige, 7);

        $this->handler()->handle(new Job(1, 'listing.expiry_notice'));

        self::assertSame([], $this->mailer->messages());
    }

    public function testDieMailNenntAnzeigeUndVerwaltungslink(): void
    {
        $userId = $this->createUser('halter@example.tld');
        $anzeige = $this->createListing($userId, $this->createSpecies());
        $this->setzeAblauf($anzeige, 7);

        $this->handler()->handle(new Job(1, 'listing.expiry_notice'));

        $mail = $this->mailer->messages()[0];
        self::assertSame('halter@example.tld', $mail->to);
        self::assertStringContainsString('Testanzeige', $mail->body);
        self::assertStringContainsString('https://test.example/meine-anzeigen/', $mail->body);
    }

    public function testDieAufraeumungLoeschtNurVerwaisteDateien(): void
    {
        $userId = $this->createUser('halter@example.tld');
        $anzeige = $this->createListing($userId, $this->createSpecies());

        $basis = sys_get_temp_dir() . '/reptilienmarkt-media-' . bin2hex(random_bytes(6));
        mkdir($basis . '/anzeigen/1', 0o775, true);

        $bekannt = 'anzeigen/1/gehoert-dazu.webp';
        $verwaist = 'anzeigen/1/niemand-kennt-mich.webp';
        file_put_contents($basis . '/' . $bekannt, 'bild');
        file_put_contents($basis . '/' . $verwaist, 'bild');

        // Alt genug: Ein laufender Upload hat seine Zeile noch nicht.
        touch($basis . '/' . $bekannt, time() - 7200);
        touch($basis . '/' . $verwaist, time() - 7200);

        $this->database->execute(
            "INSERT INTO listing_media (listing_id, media_type, path, sort_order, created_at)
             VALUES (:anzeige, 'bild', :pfad, 0, :now)",
            ['anzeige' => $anzeige, 'pfad' => $bekannt, 'now' => Timestamp::now()],
        );

        // Eine Zeile ohne Datei — sie darf nicht verschwinden.
        $this->database->execute(
            "INSERT INTO listing_media (listing_id, media_type, path, sort_order, created_at)
             VALUES (:anzeige, 'bild', 'anzeigen/1/datei-fehlt.webp', 1, :now)",
            ['anzeige' => $anzeige, 'now' => Timestamp::now()],
        );

        $handler = new MediaCleanupHandler($this->database, $basis, new NullLogger());
        $bericht = $handler->handle(new Job(1, 'media.cleanup'));

        self::assertFileExists($basis . '/' . $bekannt);
        self::assertFileDoesNotExist($basis . '/' . $verwaist);
        self::assertStringContainsString('1 verwaiste Dateien gelöscht', $bericht);
        // Eine fehlende Datei kann ein Einhaengeproblem sein — die Zeile bleibt.
        self::assertSame(
            2,
            (int) (string) $this->database->scalar('SELECT COUNT(*) FROM listing_media'),
        );

        unlink($basis . '/' . $bekannt);
        rmdir($basis . '/anzeigen/1');
        rmdir($basis . '/anzeigen');
        rmdir($basis);
    }

    private function handler(): ListingExpiryNoticeHandler
    {
        return new ListingExpiryNoticeHandler(
            $this->database,
            $this->mailer,
            new RetentionPolicy(['ablauf_erinnerung_tage' => [7, 1]], $this->clock),
            new Translator(\dirname(__DIR__, 3) . '/lang'),
            $this->clock,
            'https://test.example',
        );
    }

    private function setzeAblauf(int $listingId, int $inTagen): void
    {
        $this->database->execute(
            'UPDATE listings SET expires_at = :ablauf WHERE id = :id',
            [
                // Mitten im Fenster, damit der Test nicht an der Sekunde haengt.
                'ablauf' => Timestamp::utc($this->clock->now()->modify(\sprintf('+%d days -6 hours', $inTagen))),
                'id' => $listingId,
            ],
        );
    }
}
