<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Kernel;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Middleware\SessionMiddleware;
use Reptilienmarkt\Http\Routing\Router;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Infra\Persistence\PdoSessionRepository;
use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

/**
 * Regression zu einem Fehler auf der Live-Seite: Die Registrierung endete
 * dauerhaft mit "400 — Das Formular ist abgelaufen".
 *
 * Ursache war das Secure-Flag des Sitzungs-Cookies. Es kam aus APP_URL. Stand
 * dort https, lief die Seite aber ueber http, verwarf der Browser das Cookie
 * stillschweigend: bei jeder Anfrage eine neue Sitzung, nie ein gueltiger
 * CSRF-Token, kein Formular kam je durch.
 */
#[CoversClass(SessionManager::class)]
#[CoversClass(SessionMiddleware::class)]
#[CoversClass(Kernel::class)]
final class SessionCookieTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    public function testUeberHttpKommtDasCookieOhneSecureFlag(): void
    {
        $session = $this->session();
        $session->start($this->request(secure: false));
        $session->csrfToken();

        $cookie = (string) $session->cookieHeader();

        self::assertStringContainsString('rm_session=', $cookie);
        self::assertStringNotContainsString('Secure', $cookie);
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
    }

    public function testUeberHttpsKommtDasSecureFlagMit(): void
    {
        $session = $this->session();
        $session->start($this->request(secure: true));

        self::assertStringContainsString('Secure', (string) $session->cookieHeader());
    }

    public function testEinFormularUeberberHttpGehtDurch(): void
    {
        // Genau der Ablauf der Registrierung: Seite holen, Formular absenden.
        $session = $this->session();
        $session->start($this->request(secure: false));
        $token = $session->csrfToken();
        $session->commit();

        $cookie = $this->cookieValue((string) $session->cookieHeader());

        $zweite = $this->session();
        $zweite->start($this->request(secure: false, cookies: [SessionManager::COOKIE_NAME => $cookie]));

        self::assertTrue($zweite->verifyCsrf($token));
    }

    public function testEinFremderTokenWirdAbgelehnt(): void
    {
        $session = $this->session();
        $session->start($this->request(secure: false));
        $session->csrfToken();

        self::assertFalse($session->verifyCsrf(bin2hex(random_bytes(32))));
        self::assertFalse($session->verifyCsrf(null));
    }

    public function testOhneSitzungImBrowserNenntDieMeldungDenGrund(): void
    {
        $session = $this->session();
        $session->start($this->request(secure: false));

        try {
            $session->assertCsrf($this->request(secure: false, body: ['_csrf' => 'irgendwas']));
            self::fail('Es haette eine Ausnahme kommen muessen.');
        } catch (HttpException $exception) {
            // Ohne Token in der Sitzung liegt es am Cookie, nicht am Alter des
            // Formulars — und die Meldung soll das auch sagen.
            self::assertStringContainsString('Cookie', $exception->getMessage());
            self::assertSame(400, $exception->status);
        }
    }

    public function testEinAbgelaufenerTokenMeldetEtwasAnderes(): void
    {
        $session = $this->session();
        $session->start($this->request(secure: false));
        $session->csrfToken();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/abgelaufen/');

        $session->assertCsrf($this->request(secure: false, body: ['_csrf' => bin2hex(random_bytes(32))]));
    }

    public function testAuchEineFehlerseiteSetztDasCookie(): void
    {
        // Frueher lag die Fehlerbehandlung um die Middleware herum: Eine
        // Ausnahme verliess die Kette, das Cookie wurde nie gesetzt — und der
        // naechste Versuch scheiterte genauso.
        $kernel = $this->kernel(static function (): Response {
            throw HttpException::badRequest('Das Formular ist abgelaufen.');
        });

        $antwort = $kernel->handle($this->request(secure: false, path: '/test'));

        self::assertSame(400, $antwort->status);
        self::assertArrayHasKey('set-cookie', $antwort->headers);
        self::assertStringContainsString('rm_session=', (string) $antwort->headers['set-cookie']);
    }

    public function testHtmlSeitenLandenInKeinemFremdenZwischenspeicher(): void
    {
        $kernel = $this->kernel(static fn(): Response => Response::html('<p>Formular mit Token</p>'));

        $antwort = $kernel->handle($this->request(secure: false, path: '/test'));

        // Ein Proxy, der diese Seite ablegt, verteilt den CSRF-Token eines
        // Besuchers an alle folgenden.
        self::assertSame('private, no-store', $antwort->headers['cache-control'] ?? null);
    }

    public function testDerTokenUeberlebtDieAnmeldung(): void
    {
        $userId = $this->createUser('kunde@example.tld');

        $session = $this->session();
        $session->start($this->request(secure: false));
        $token = $session->csrfToken();
        $session->login($userId);

        // Die Kennung wechselt (gegen Session Fixation), der Token bleibt —
        // sonst waere jedes offene Formular nach dem Login ungueltig.
        self::assertSame($token, $session->csrfToken());
        self::assertSame($userId, $session->userId());
    }

    private function session(): SessionManager
    {
        return new SessionManager(new PdoSessionRepository($this->database), $this->clock);
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $body
     */
    private function request(bool $secure, array $cookies = [], array $body = [], string $path = '/registrieren'): Request
    {
        return new Request(
            $body === [] ? 'GET' : 'POST',
            $path,
            [],
            $body,
            ['user-agent' => 'Test/1.0'],
            [],
            $cookies,
            '203.0.113.7',
            [],
            null,
            $secure,
        );
    }

    private function kernel(callable $action): Kernel
    {
        $router = new Router();
        $router->get('/test', TestEndpoint::class, 'run', 'test');

        $container = new Container();
        $container->set(TestEndpoint::class, static fn(): TestEndpoint => new TestEndpoint($action));

        return new Kernel($router, $container, [new SessionMiddleware($this->session())]);
    }

    private function cookieValue(string $header): string
    {
        preg_match('/rm_session=([^;]+)/', $header, $treffer);

        return $treffer[1] ?? '';
    }
}

final readonly class TestEndpoint
{
    /**
     * @param callable(): Response $action
     */
    public function __construct(private mixed $action) {}

    public function run(Request $request): Response
    {
        return ($this->action)();
    }
}
