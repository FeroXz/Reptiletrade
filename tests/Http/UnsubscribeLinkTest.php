<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Auth\PasswordHasher;
use Reptilienmarkt\Domain\Auth\TokenService;
use Reptilienmarkt\Domain\Auth\TotpAuthenticator;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Domain\Notification\NotificationChannel;
use Reptilienmarkt\Domain\Notification\NotificationPreferenceService;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Domain\Trust\TrustConfiguration;
use Reptilienmarkt\Domain\User\AccountService;
use Reptilienmarkt\Http\Controller\AccountController;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\View\TwigFactory;
use Reptilienmarkt\Infra\Mail\PreferenceAwareMailer;
use Reptilienmarkt\Infra\Mail\QueueingMailer;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoMailOutboxRepository;
use Reptilienmarkt\Infra\Persistence\PdoNotificationPreferenceRepository;
use Reptilienmarkt\Infra\Persistence\PdoRateLimitRepository;
use Reptilienmarkt\Infra\Persistence\PdoSessionRepository;
use Reptilienmarkt\Infra\Persistence\PdoTokenRepository;
use Reptilienmarkt\Infra\Persistence\PdoUserDocumentRepository;
use Reptilienmarkt\Infra\Persistence\PdoUserRepository;
use Reptilienmarkt\Infra\Persistence\PdoVerificationRepository;
use Reptilienmarkt\Infra\Storage\PrivateStorage;
use Reptilienmarkt\Support\Log\NullLogger;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\CollectingMailer;
use Reptilienmarkt\Tests\Support\FrozenClock;
use Reptilienmarkt\Tests\Support\StubViewer;

/**
 * Der Abmeldelink aus einer Mail — ueber HTTP.
 *
 * Der Kern: Ein GET aendert nichts. Mailfilter rufen Links in Mails ungefragt
 * auf; ein abmeldender GET meldet Leute ab, die nie geklickt haben. Und der
 * POST muss ohne Sitzung und ohne Formularfelder durchgehen, weil ihn beim
 * Ein-Klick nach RFC 8058 der Mailanbieter schickt und nicht der Browser des
 * Nutzers.
 */
#[CoversClass(AccountController::class)]
#[CoversClass(PreferenceAwareMailer::class)]
final class UnsubscribeLinkTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-10T09:00:00+00:00'));
        $this->userId = $this->createUser('halter@example.tld');
    }

    public function testEinGetAufDenAbmeldelinkAendertNichts(): void
    {
        $antwort = $this->controller()->confirmUnsubscribe($this->request('GET'));

        self::assertSame(200, $antwort->status);
        // Die Seite nennt den Kanal und stellt eine Frage …
        self::assertStringContainsString('Ablaufende Anzeigen', $antwort->body);
        self::assertStringContainsString('<form method="post"', $antwort->body);
        // … und genau das ist bisher alles passiert.
        self::assertTrue($this->preferences()->mayNotify($this->userId, NotificationChannel::AnzeigeAblauf));
        self::assertSame(0, (int) (string) $this->database->scalar('SELECT COUNT(*) FROM notification_preferences'));
        self::assertSame(0, (int) (string) $this->database->scalar('SELECT COUNT(*) FROM audit_log'));
    }

    public function testDerGetVerraetNichtObEsDenTokenGibt(): void
    {
        $echt = $this->controller()->confirmUnsubscribe($this->request('GET'));
        $erfunden = $this->controller()->confirmUnsubscribe(
            $this->request('GET', token: '1-' . str_repeat('a', 64)),
        );

        self::assertSame(200, $echt->status);
        self::assertSame(404, $erfunden->status);
        self::assertStringContainsString('nicht mehr gültig', $erfunden->body);
        self::assertTrue($this->preferences()->mayNotify($this->userId, NotificationChannel::AnzeigeAblauf));
    }

    public function testDerPostMeldetAb(): void
    {
        $antwort = $this->controller()->unsubscribe($this->request('POST'));

        self::assertSame(200, $antwort->status);
        self::assertFalse($this->preferences()->mayNotify($this->userId, NotificationChannel::AnzeigeAblauf));
        // Andere Kanaele bleiben, wie sie waren.
        self::assertTrue($this->preferences()->mayNotify($this->userId, NotificationChannel::NachrichtNeu));
    }

    /**
     * Der Ein-Klick-POST nach RFC 8058: Er kommt von Gmail oder Outlook, nicht
     * aus dem Browser des Nutzers. Kein Cookie, keine Sitzung, kein CSRF-Feld —
     * der Rumpf traegt nur "List-Unsubscribe=One-Click", und selbst der darf
     * fehlen.
     */
    public function testDerEinKlickPostOhneSitzungUndOhneFelderGehtDurch(): void
    {
        $anfrage = new Request(
            'POST',
            '/abmelden/x',
            ['kanal' => NotificationChannel::AnzeigeAblauf->value],
            [],
            ['content-type' => 'application/x-www-form-urlencoded'],
            ['token' => $this->preferences()->issueUnsubscribeToken($this->userId)],
            rawBody: '',
        );

        $antwort = $this->controller(sitzung: false)->unsubscribe($anfrage);

        self::assertSame(200, $antwort->status);
        self::assertFalse($this->preferences()->mayNotify($this->userId, NotificationChannel::AnzeigeAblauf));
    }

    public function testEinPostMitEntwertetemTokenAendertNichts(): void
    {
        $token = $this->preferences()->issueUnsubscribeToken($this->userId);
        $this->preferences()->rotateUnsubscribeSecret($this->userId);

        $antwort = $this->controller()->unsubscribe($this->request('POST', token: $token));

        self::assertSame(404, $antwort->status);
        self::assertTrue($this->preferences()->mayNotify($this->userId, NotificationChannel::AnzeigeAblauf));
    }

    /**
     * Ohne List-Unsubscribe-Post bietet kein Mailprogramm den Ein-Klick an —
     * es oeffnet stattdessen die Seite. Der Kopf gehoert also zum POST dazu.
     */
    public function testDieMailKuendigtDenEinKlickAn(): void
    {
        $this->mailer()->send($this->mail());

        $kopfzeilen = $this->headers();

        self::assertSame('List-Unsubscribe=One-Click', $kopfzeilen['List-Unsubscribe-Post'] ?? null);
        self::assertStringStartsWith('<https://test.example/abmelden/', (string) ($kopfzeilen['List-Unsubscribe'] ?? ''));
        self::assertStringNotContainsString('mailto:', (string) ($kopfzeilen['List-Unsubscribe'] ?? ''));
    }

    /**
     * Ein mailto: nur mit betreutem Postfach: Wer ins Leere schreibt, haelt
     * sich fuer abgemeldet und bekommt weiter Post.
     */
    public function testDasMailtoStehtNurWennEinPostfachKonfiguriertIst(): void
    {
        $this->mailer('abmelden@test.example')->send($this->mail());

        self::assertStringContainsString(
            '<mailto:abmelden@test.example?subject=',
            (string) ($this->headers()['List-Unsubscribe'] ?? ''),
        );
    }

    private function request(string $method, ?string $token = null, string $kanal = 'anzeige.ablauf'): Request
    {
        return new Request(
            $method,
            '/abmelden/x',
            ['kanal' => $kanal],
            [],
            [],
            ['token' => $token ?? $this->preferences()->issueUnsubscribeToken($this->userId)],
        );
    }

    private function preferences(): NotificationPreferenceService
    {
        return new NotificationPreferenceService(
            new PdoNotificationPreferenceRepository($this->database),
            new PdoAuditLog($this->database),
            $this->clock,
        );
    }

    private function mailer(string $postfach = ''): PreferenceAwareMailer
    {
        return new PreferenceAwareMailer(
            new QueueingMailer(new PdoMailOutboxRepository($this->database), $this->clock, new NullLogger()),
            $this->preferences(),
            new Translator(\dirname(__DIR__, 2) . '/lang'),
            'https://test.example',
            $postfach,
        );
    }

    private function mail(): MailMessage
    {
        return new MailMessage(
            'halter@example.tld',
            'Deine Anzeige läuft ab',
            'Text',
            'Halter',
            NotificationChannel::AnzeigeAblauf->value,
            $this->userId,
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $zeile = $this->database->selectOne('SELECT headers_json FROM mail_outbox ORDER BY id DESC');
        self::assertNotNull($zeile);

        /** @var array<string, string> $kopfzeilen */
        $kopfzeilen = json_decode((string) $zeile['headers_json'], true, 512, \JSON_THROW_ON_ERROR);

        return $kopfzeilen;
    }

    /**
     * @param bool $sitzung Soll es ueberhaupt eine Sitzung geben? Beim
     *                      Ein-Klick des Mailanbieters gibt es keine.
     */
    private function controller(bool $sitzung = true): AccountController
    {
        $session = new SessionManager(new PdoSessionRepository($this->database), $this->clock);

        if ($sitzung) {
            $session->start(new Request('GET', '/abmelden/x'));
        }

        /** @var array<string, mixed> $trustConfig */
        $trustConfig = require \dirname(__DIR__, 2) . '/config/trust.php';

        $translator = new Translator(\dirname(__DIR__, 2) . '/lang');
        $users = new PdoUserRepository($this->database);

        return new AccountController(
            new AccountService(
                $users,
                new PdoVerificationRepository($this->database),
                new TokenService(new PdoTokenRepository($this->database), $this->clock),
                new PasswordHasher(),
                new TotpAuthenticator($this->clock),
                new PdoSessionRepository($this->database),
                new CollectingMailer(),
                new PdoAuditLog($this->database),
                $this->clock,
                $translator,
            ),
            new PdoUserDocumentRepository($this->database),
            new PrivateStorage(sys_get_temp_dir()),
            new TotpAuthenticator($this->clock),
            $this->preferences(),
            new PdoSessionRepository($this->database),
            new RateLimiter(
                new PdoRateLimitRepository($this->database),
                $this->clock,
                (new TrustConfiguration($trustConfig))->rateLimits(),
            ),
            new StubViewer(null),
            $session,
            $translator,
            TwigFactory::create(\dirname(__DIR__, 2) . '/templates', false, null, $translator),
        );
    }
}
