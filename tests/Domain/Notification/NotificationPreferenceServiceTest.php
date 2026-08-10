<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Notification;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Domain\Notification\NotificationChannel;
use Reptilienmarkt\Domain\Notification\NotificationException;
use Reptilienmarkt\Domain\Notification\NotificationPreferenceService;
use Reptilienmarkt\Infra\Mail\PreferenceAwareMailer;
use Reptilienmarkt\Infra\Mail\QueueingMailer;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoMailOutboxRepository;
use Reptilienmarkt\Infra\Persistence\PdoNotificationPreferenceRepository;
use Reptilienmarkt\Support\Log\NullLogger;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(NotificationPreferenceService::class)]
#[CoversClass(NotificationChannel::class)]
#[CoversClass(PreferenceAwareMailer::class)]
#[CoversClass(PdoNotificationPreferenceRepository::class)]
final class NotificationPreferenceServiceTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-09T10:00:00Z'));
        $this->userId = $this->createUser('halter@example.tld');
    }

    public function testDieVoreinstellungIstAnAusserBeiSuchtreffern(): void
    {
        $dienst = $this->service();

        self::assertTrue($dienst->mayNotify($this->userId, NotificationChannel::NachrichtNeu));
        self::assertTrue($dienst->mayNotify($this->userId, NotificationChannel::AnzeigeAblauf));
        self::assertTrue($dienst->mayNotify($this->userId, NotificationChannel::HandelBestaetigung));
        // Ungefragt taeglich Trefferlisten waere genau das, was die
        // Abmeldefunktion noetig macht.
        self::assertFalse($dienst->mayNotify($this->userId, NotificationChannel::SucheTreffer));
    }

    public function testEinAbgeschalteterKanalErzeugtKeineZeileImPostausgang(): void
    {
        $this->service()->save($this->userId, []);

        $erfolg = $this->mailer()->send(new MailMessage(
            'halter@example.tld',
            'Neue Nachricht',
            'Text',
            'Halter',
            NotificationChannel::NachrichtNeu->value,
            $this->userId,
        ));

        // "true", weil nicht schreiben zu duerfen kein Fehlschlag ist.
        self::assertTrue($erfolg);
        self::assertSame(0, $this->zeilen());
    }

    public function testEinEingeschalteterKanalTraegtAbmeldelinkUndKopfzeile(): void
    {
        $this->mailer()->send(new MailMessage(
            'halter@example.tld',
            'Deine Anzeige läuft ab',
            'Ursprungstext',
            'Halter',
            NotificationChannel::AnzeigeAblauf->value,
            $this->userId,
        ));

        $zeile = $this->database->selectOne('SELECT body, headers_json FROM mail_outbox');

        self::assertNotNull($zeile);
        self::assertStringContainsString('Ursprungstext', (string) $zeile['body']);
        self::assertStringContainsString('https://test.example/abmelden/', (string) $zeile['body']);
        self::assertStringContainsString('kanal=anzeige.ablauf', (string) $zeile['body']);
        self::assertStringContainsString('List-Unsubscribe', (string) $zeile['headers_json']);
    }

    public function testSystemwichtigeMailsGehenImmerRausUndOhneAbmeldelink(): void
    {
        // Auch wenn jemand alles abgewaehlt hat, was sich abwaehlen laesst.
        $this->service()->save($this->userId, []);

        $this->mailer()->send(new MailMessage(
            'halter@example.tld',
            'Bitte bestätige deine E-Mail-Adresse',
            'Dein Link.',
            'Halter',
            'konto.verify',
            $this->userId,
        ));

        $zeile = $this->database->selectOne('SELECT body, headers_json FROM mail_outbox');

        self::assertNotNull($zeile);
        self::assertStringNotContainsString('/abmelden/', (string) $zeile['body']);
        self::assertSame('{}', (string) $zeile['headers_json']);
    }

    public function testSystemWichtigLaesstSichNichtAbschalten(): void
    {
        $dienst = $this->service();

        // Weder ueber das Formular …
        $dienst->save($this->userId, []);
        self::assertTrue($dienst->mayNotify($this->userId, NotificationChannel::SystemWichtig));
        self::assertSame(
            0,
            (int) (string) $this->database->scalar(
                "SELECT COUNT(*) FROM notification_preferences WHERE channel_key = 'system.wichtig'",
            ),
        );

        // … noch ueber den Abmeldelink.
        $token = $dienst->issueUnsubscribeToken($this->userId);

        $this->expectException(NotificationException::class);
        $dienst->unsubscribe($token, NotificationChannel::SystemWichtig);
    }

    public function testDerAbmeldelinkSchaltetGenauEinenKanalAb(): void
    {
        $dienst = $this->service();
        $token = $dienst->issueUnsubscribeToken($this->userId);

        self::assertSame($this->userId, $dienst->unsubscribe($token, NotificationChannel::AnzeigeAblauf));

        self::assertFalse($dienst->mayNotify($this->userId, NotificationChannel::AnzeigeAblauf));
        self::assertTrue($dienst->mayNotify($this->userId, NotificationChannel::NachrichtNeu));
        self::assertTrue($dienst->mayNotify($this->userId, NotificationChannel::HandelBestaetigung));
    }

    public function testEinFremderTokenVeraendertNichts(): void
    {
        $dienst = $this->service();
        $dienst->issueUnsubscribeToken($this->userId);

        try {
            $dienst->unsubscribe(bin2hex(random_bytes(32)), NotificationChannel::AnzeigeAblauf);
            self::fail('Ein fremder Token darf nichts abschalten.');
        } catch (NotificationException) {
            // erwartet
        }

        self::assertTrue($dienst->mayNotify($this->userId, NotificationChannel::AnzeigeAblauf));
        self::assertSame(0, (int) (string) $this->database->scalar('SELECT COUNT(*) FROM notification_preferences'));
    }

    /**
     * Der Kern von Weg (a): Wer die Kontonummer kennt — sie steht im Link —,
     * kommt ohne das Geheimnis des Kontos trotzdem nicht weiter.
     */
    public function testEineVerfaelschteSignaturVeraendertNichts(): void
    {
        $dienst = $this->service();
        $token = $dienst->issueUnsubscribeToken($this->userId);

        // Ein einziges Zeichen der Signatur gedreht, die Kontonummer bleibt.
        $verfaelscht = substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a');

        try {
            $dienst->unsubscribe($verfaelscht, NotificationChannel::AnzeigeAblauf);
            self::fail('Eine verfälschte Signatur darf nichts abschalten.');
        } catch (NotificationException) {
            // erwartet
        }

        self::assertNull($dienst->accountForToken($verfaelscht));
        self::assertTrue($dienst->mayNotify($this->userId, NotificationChannel::AnzeigeAblauf));
        self::assertSame(0, (int) (string) $this->database->scalar('SELECT COUNT(*) FROM notification_preferences'));
    }

    /**
     * Die Signatur eines Kontos vor die Nummer eines anderen gesetzt.
     */
    public function testDieSignaturEinesKontosGiltNichtFuerEinAnderes(): void
    {
        $dienst = $this->service();
        $zweiter = $this->createUser('zweiter@example.tld');
        $signatur = explode('-', $dienst->issueUnsubscribeToken($this->userId), 2)[1];

        try {
            $dienst->unsubscribe($zweiter . '-' . $signatur, NotificationChannel::AnzeigeAblauf);
            self::fail('Eine fremde Signatur darf nichts abschalten.');
        } catch (NotificationException) {
            // erwartet
        }

        self::assertTrue($dienst->mayNotify($zweiter, NotificationChannel::AnzeigeAblauf));
        self::assertTrue($dienst->mayNotify($this->userId, NotificationChannel::AnzeigeAblauf));
    }

    public function testDerTokenBleibtUeberMehrereAusstellungenHinwegDerselbe(): void
    {
        $dienst = $this->service();

        self::assertSame(
            $dienst->issueUnsubscribeToken($this->userId),
            $dienst->issueUnsubscribeToken($this->userId),
        );
    }

    /**
     * Der Befund aus Phase 12: Frueher trug jede Mail einen eigenen Token und
     * entwertete damit den Link der vorigen.
     */
    public function testZweiMailsTragenDenselbenAbmeldelinkUndBeideWirken(): void
    {
        $mailer = $this->mailer();

        $mailer->send(new MailMessage(
            'halter@example.tld',
            'Deine Anzeige läuft ab',
            'Erste',
            'Halter',
            NotificationChannel::AnzeigeAblauf->value,
            $this->userId,
        ));
        $mailer->send(new MailMessage(
            'halter@example.tld',
            'Neue Nachricht',
            'Zweite',
            'Halter',
            NotificationChannel::NachrichtNeu->value,
            $this->userId,
        ));

        $token = $this->tokensAusDemPostausgang();

        self::assertCount(2, $token);
        self::assertSame($token[0], $token[1]);

        $dienst = $this->service();

        // Der Link der *aelteren* Mail zuerst — genau der war frueher tot.
        self::assertSame($this->userId, $dienst->unsubscribe($token[0], NotificationChannel::AnzeigeAblauf));
        self::assertSame($this->userId, $dienst->unsubscribe($token[1], NotificationChannel::NachrichtNeu));

        self::assertFalse($dienst->mayNotify($this->userId, NotificationChannel::AnzeigeAblauf));
        self::assertFalse($dienst->mayNotify($this->userId, NotificationChannel::NachrichtNeu));
    }

    /**
     * Kein Schreibzugriff auf users beim Versand — geprueft mit einem Trigger,
     * der jede Schreiboperation abbricht. Ein Vergleich der Zeile vorher und
     * nachher wuerde nur zeigen, dass sich nichts geaendert hat, nicht dass
     * nichts geschrieben wurde.
     */
    public function testEinVersandSchreibtNichtInDieKontotabelle(): void
    {
        $this->database->execute(
            <<<'SQL'
                CREATE TRIGGER users_ist_beim_versand_tabu
                BEFORE UPDATE ON users
                BEGIN SELECT RAISE(ABORT, 'Der Versand hat in users geschrieben.'); END
                SQL,
        );

        $this->mailer()->send(new MailMessage(
            'halter@example.tld',
            'Deine Anzeige läuft ab',
            'Text',
            'Halter',
            NotificationChannel::AnzeigeAblauf->value,
            $this->userId,
        ));

        self::assertSame(1, $this->zeilen());
    }

    public function testDerWechselDesGeheimnissesEntwertetAlleLinks(): void
    {
        $dienst = $this->service();
        $alt = $dienst->issueUnsubscribeToken($this->userId);

        $dienst->rotateUnsubscribeSecret($this->userId);

        $neu = $dienst->issueUnsubscribeToken($this->userId);
        self::assertNotSame($alt, $neu);
        self::assertNull($dienst->accountForToken($alt));

        self::assertSame(
            1,
            (int) (string) $this->database->scalar(
                "SELECT COUNT(*) FROM audit_log WHERE action = 'notification.unsubscribe_secret_rotated'",
            ),
        );

        try {
            $dienst->unsubscribe($alt, NotificationChannel::AnzeigeAblauf);
            self::fail('Ein entwerteter Link darf nichts abschalten.');
        } catch (NotificationException) {
            // erwartet
        }

        self::assertTrue($dienst->mayNotify($this->userId, NotificationChannel::AnzeigeAblauf));
        self::assertSame($this->userId, $dienst->unsubscribe($neu, NotificationChannel::AnzeigeAblauf));
    }

    public function testEinLeererTokenTrifftNichtDasKontoOhneGeheimnis(): void
    {
        $ohneGeheimnis = $this->createUser('zweiter@example.tld');
        $this->database->execute(
            'UPDATE users SET unsubscribe_secret = NULL WHERE id = :id',
            ['id' => $ohneGeheimnis],
        );

        $this->expectException(NotificationException::class);

        try {
            $this->service()->unsubscribe('', NotificationChannel::AnzeigeAblauf);
        } finally {
            self::assertTrue($this->service()->mayNotify($ohneGeheimnis, NotificationChannel::AnzeigeAblauf));
        }
    }

    /**
     * @return list<string>
     */
    private function tokensAusDemPostausgang(): array
    {
        $token = [];

        foreach ($this->database->select('SELECT body FROM mail_outbox ORDER BY id') as $zeile) {
            if (preg_match('#/abmelden/([^?\s]+)#', (string) $zeile['body'], $treffer) === 1) {
                $token[] = rawurldecode($treffer[1]);
            }
        }

        return $token;
    }

    public function testDieAenderungStehtImVerlauf(): void
    {
        $this->service()->save($this->userId, [NotificationChannel::SucheTreffer->value]);

        self::assertSame(
            1,
            (int) (string) $this->database->scalar(
                "SELECT COUNT(*) FROM audit_log WHERE action = 'notification.preferences_changed'",
            ),
        );
    }

    private function service(): NotificationPreferenceService
    {
        return new NotificationPreferenceService(
            new PdoNotificationPreferenceRepository($this->database),
            new PdoAuditLog($this->database),
            $this->clock,
        );
    }

    private function mailer(): PreferenceAwareMailer
    {
        return new PreferenceAwareMailer(
            new QueueingMailer(new PdoMailOutboxRepository($this->database), $this->clock, new NullLogger()),
            $this->service(),
            new Translator(\dirname(__DIR__, 3) . '/lang'),
            'https://test.example',
        );
    }

    private function zeilen(): int
    {
        return (int) (string) $this->database->scalar('SELECT COUNT(*) FROM mail_outbox');
    }
}
