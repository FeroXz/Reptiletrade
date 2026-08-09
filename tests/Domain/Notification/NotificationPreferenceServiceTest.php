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

    public function testEinUeberholterTokenVeraendertNichts(): void
    {
        $dienst = $this->service();
        $alt = $dienst->issueUnsubscribeToken($this->userId);
        $dienst->issueUnsubscribeToken($this->userId);

        $this->expectException(NotificationException::class);

        try {
            $dienst->unsubscribe($alt, NotificationChannel::AnzeigeAblauf);
        } finally {
            self::assertTrue($dienst->mayNotify($this->userId, NotificationChannel::AnzeigeAblauf));
        }
    }

    public function testEinLeererTokenTrifftNichtDasKontoOhneToken(): void
    {
        $ohneToken = $this->createUser('zweiter@example.tld');

        $this->expectException(NotificationException::class);

        try {
            $this->service()->unsubscribe('', NotificationChannel::AnzeigeAblauf);
        } finally {
            self::assertTrue($this->service()->mayNotify($ohneToken, NotificationChannel::AnzeigeAblauf));
        }
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
