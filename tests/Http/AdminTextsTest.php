<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Admin\DashboardService;
use Reptilienmarkt\Domain\Admin\SpeciesCatalogService;
use Reptilienmarkt\Domain\Privacy\RetentionPolicy;
use Reptilienmarkt\Domain\Site\UiTextService;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\Controller\AdminController;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Session\NotAuthenticatedException;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\View\TwigFactory;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoJobRepository;
use Reptilienmarkt\Infra\Persistence\PdoLegalTextRepository;
use Reptilienmarkt\Infra\Persistence\PdoMorphRepository;
use Reptilienmarkt\Infra\Persistence\PdoSessionRepository;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Infra\Persistence\PdoTextOverrideRepository;
use Reptilienmarkt\Legal\LegalTextReview;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;
use Reptilienmarkt\Tests\Support\StubViewer;

/**
 * Die Textverwaltung ueber HTTP: Zugriffsschutz, Speichern, Zuruecksetzen.
 */
#[CoversClass(AdminController::class)]
#[CoversClass(UiTextService::class)]
final class AdminTextsTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private Translator $translator;

    private PdoTextOverrideRepository $overrides;

    private int $adminId;

    private string $csrf = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-05T12:00:00+00:00'));
        $this->adminId = $this->createUser('verwaltung@example.tld');
        $this->overrides = new PdoTextOverrideRepository($this->database);
        $this->translator = new Translator(\dirname(__DIR__, 2) . '/lang', Translator::BASE_LOCALE, $this->overrides);
    }

    private function user(Role $role = Role::Admin, ?int $id = null): User
    {
        return new User($id ?? $this->adminId, 'verwaltung@example.tld', 'Verwaltung', $role);
    }

    private function controller(?User $viewer): AdminController
    {
        $session = new SessionManager(new PdoSessionRepository($this->database), $this->clock);
        $session->start(new Request('GET', '/admin/texte'));
        $this->csrf = $session->csrfToken();

        /** @var array<string, mixed> $fristen */
        $fristen = require \dirname(__DIR__, 2) . '/config/aufbewahrung.php';

        $jobs = new PdoJobRepository($this->database);
        $audit = new PdoAuditLog($this->database);

        return new AdminController(
            new DashboardService(
                $this->database,
                $jobs,
                new LegalTextReview(new PdoLegalTextRepository($this->database), $this->clock),
                $this->clock,
            ),
            new SpeciesCatalogService(
                new PdoSpeciesRepository($this->database),
                new PdoMorphRepository($this->database),
                $audit,
            ),
            $jobs,
            new RetentionPolicy($fristen, $this->clock),
            new UiTextService($this->translator, $this->overrides, $audit, $this->clock),
            new StubViewer($viewer),
            $session,
            $this->translator,
            TwigFactory::create(\dirname(__DIR__, 2) . '/templates', false, null, $this->translator),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): Request
    {
        return new Request('POST', '/admin/texte', [], $body + ['_csrf' => $this->csrf]);
    }

    // ------------------------------------------------------ Zugriffsschutz

    public function testOhneAnmeldungKeineTextverwaltung(): void
    {
        $this->expectException(NotAuthenticatedException::class);

        $this->controller(null)->texts(new Request('GET', '/admin/texte'));
    }

    /**
     * 404 statt 403 — die Verwaltung verraet sich nicht durch eine Ablehnung.
     */
    public function testEinGewoehnlichesKontoSiehtSieNicht(): void
    {
        try {
            $this->controller($this->user(Role::Seller))->texts(new Request('GET', '/admin/texte'));
            self::fail('Ein Verkäuferkonto darf die Textverwaltung nicht sehen.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }
    }

    /**
     * Auch die Moderation reicht nicht: Wer Texte aendert, aendert, was die
     * Plattform ihren Nutzern zusagt.
     */
    public function testAuchDieModerationDarfNichtSpeichern(): void
    {
        try {
            $this->controller($this->user(Role::Moderator))->saveTexts($this->post(['texte' => ['postfach.titel' => 'X']]));
            self::fail('Die Moderation darf keine Texte ändern.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }
    }

    public function testOhneCsrfTokenWirdNichtGespeichert(): void
    {
        $controller = $this->controller($this->user());

        try {
            $controller->saveTexts(new Request('POST', '/admin/texte', [], ['texte' => ['postfach.titel' => 'Nachrichten']]));
            self::fail('Ohne CSRF-Token darf nichts gespeichert werden.');
        } catch (HttpException $exception) {
            self::assertSame(400, $exception->status);
        }

        self::assertSame('Postfach', $this->translator->translate('postfach.titel'));
    }

    // -------------------------------------------------------------- Ansicht

    public function testDieListeZeigtSchluesselUndText(): void
    {
        $antwort = $this->controller($this->user())->texts(new Request('GET', '/admin/texte'));

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('postfach.titel', $antwort->body);
        self::assertStringContainsString('Postfach', $antwort->body);
    }

    public function testDieSucheGrenztEin(): void
    {
        $antwort = $this->controller($this->user())->texts(
            new Request('GET', '/admin/texte', ['q' => 'postfach.titel']),
        );

        self::assertStringContainsString('postfach.titel', $antwort->body);
        self::assertStringNotContainsString('admin.artenstamm', $antwort->body);
    }

    // ------------------------------------------------------------ Speichern

    public function testEinGeaenderterTextGiltSofort(): void
    {
        $antwort = $this->controller($this->user())->saveTexts($this->post([
            'texte' => ['postfach.titel' => 'Nachrichten'],
        ]));

        self::assertSame(302, $antwort->status);
        self::assertSame('Nachrichten', $this->translator->translate('postfach.titel'));
    }

    public function testUnveraenderteFelderWerdenNichtGespeichert(): void
    {
        $this->controller($this->user())->saveTexts($this->post([
            'texte' => ['postfach.titel' => 'Postfach', 'admin.titel' => 'Verwaltung'],
        ]));

        $anzahl = $this->database->scalar('SELECT COUNT(*) FROM ui_texts');

        self::assertSame(0, (int) (is_numeric($anzahl) ? $anzahl : 0));
    }

    public function testEinTextOhnePlatzhalterWirdAbgelehntUndDerRestGespeichert(): void
    {
        $this->controller($this->user())->saveTexts($this->post([
            'texte' => [
                'konto.telefon_code_gesendet' => 'Der Code ist unterwegs.',
                'postfach.titel' => 'Nachrichten',
            ],
        ]));

        // Der fehlerhafte Text bleibt, wie er war; der andere ist gespeichert.
        self::assertStringContainsString('{minuten}', $this->rohtext('konto.telefon_code_gesendet'));
        self::assertSame('Nachrichten', $this->translator->translate('postfach.titel'));
    }

    public function testZuruecksetzenStelltDenAusgelieferteTextWiederHer(): void
    {
        $controller = $this->controller($this->user());
        $controller->saveTexts($this->post(['texte' => ['postfach.titel' => 'Nachrichten']]));

        $antwort = $controller->saveTexts($this->post(['zuruecksetzen' => 'postfach.titel']));

        self::assertSame(302, $antwort->status);
        self::assertSame('Postfach', $this->translator->translate('postfach.titel'));
    }

    /**
     * Suche und Bereich ueberleben das Speichern — sonst steht die Verwaltung
     * nach jeder Aenderung wieder am Anfang der Liste.
     */
    public function testDerFilterUeberlebtDasSpeichern(): void
    {
        $antwort = $this->controller($this->user())->saveTexts($this->post([
            'q' => 'postfach',
            'bereich' => 'postfach',
            'texte' => ['postfach.titel' => 'Nachrichten'],
        ]));

        self::assertSame('/admin/texte?q=postfach&bereich=postfach', $antwort->headers['location']);
    }

    /**
     * Der geaenderte Text erscheint ueberall dort, wo der Schluessel steht —
     * nicht nur in der Verwaltung.
     */
    public function testDieAenderungWirktInDerGesamtenOberflaeche(): void
    {
        $controller = $this->controller($this->user());
        $controller->saveTexts($this->post(['texte' => ['admin.titel' => 'Schaltzentrale']]));

        $antwort = $controller->texts(new Request('GET', '/admin/texte'));

        self::assertStringContainsString('Schaltzentrale', $antwort->body);
    }

    private function rohtext(string $key): string
    {
        return $this->translator->translate($key);
    }
}
