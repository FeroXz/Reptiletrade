<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Content\MarkdownRenderer;
use Reptilienmarkt\Domain\Site\SiteIdentity;
use Reptilienmarkt\Domain\Site\SiteIdentityException;
use Reptilienmarkt\Domain\Site\SiteIdentityService;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\Controller\AdminLegalController;
use Reptilienmarkt\Http\Controller\LegalPageController;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Session\NotAuthenticatedException;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\View\TwigFactory;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoLegalTextRepository;
use Reptilienmarkt\Infra\Persistence\PdoSessionRepository;
use Reptilienmarkt\Infra\Persistence\PdoSiteIdentityOverrideRepository;
use Reptilienmarkt\Legal\LegalPageException;
use Reptilienmarkt\Legal\LegalPageService;
use Reptilienmarkt\Legal\LegalText;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;
use Reptilienmarkt\Tests\Support\StubViewer;

/**
 * Impressum, Datenschutzerklaerung und Nutzungsbedingungen ueber die
 * Verwaltung pflegen.
 */
#[CoversClass(AdminLegalController::class)]
#[CoversClass(SiteIdentityService::class)]
#[CoversClass(LegalPageService::class)]
final class AdminLegalTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private PdoSiteIdentityOverrideRepository $overrides;

    private PdoLegalTextRepository $texts;

    private SessionManager $session;

    private string $csrf = '';

    private int $adminId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-09T10:00:00+00:00'));
        $this->overrides = new PdoSiteIdentityOverrideRepository($this->database);
        $this->texts = new PdoLegalTextRepository($this->database);
        $this->adminId = $this->createUser('verwaltung@example.tld');

        $this->session = new SessionManager(new PdoSessionRepository($this->database), $this->clock);
        $this->session->start(new Request('GET', '/admin/recht'));
        $this->csrf = $this->session->csrfToken();
    }

    // ------------------------------------------------------ Zugriffsschutz

    public function testOhneAnmeldungKeinZugriff(): void
    {
        $this->expectException(NotAuthenticatedException::class);

        $this->controller(null)->index(new Request('GET', '/admin/recht'));
    }

    public function testDieRedaktionDarfRechtsseitenNichtAendern(): void
    {
        // Wer diese Seiten aendert, aendert, wofuer der Betreiber haftet —
        // das ist etwas anderes als einen Beitrag zu schreiben.
        try {
            $this->controller($this->user(Role::Seller))->index(new Request('GET', '/admin/recht'));
            self::fail('Der Zugriff haette abgewiesen werden muessen.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }
    }

    public function testDieVerwaltungSiehtDieStammdaten(): void
    {
        $response = $this->controller($this->user(Role::Admin))->index(new Request('GET', '/admin/recht'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Name oder Firma', $response->body);
        self::assertStringContainsString('Hosting-Anbieter', $response->body);
    }

    // ---------------------------------------------------------- Stammdaten

    public function testEineAngabeWirdGespeichertUndWirktAufDerSeite(): void
    {
        $this->controller($this->user(Role::Admin))->save($this->post([
            'feld' => ['anbieter.name' => 'Reptilienmarkt GmbH'],
            'schalter' => ['unvollstaendig' => '1'],
        ]));

        self::assertSame('Reptilienmarkt GmbH', $this->overrides->all()['anbieter.name'] ?? null);

        // Und sie steht auf der oeffentlichen Seite.
        $body = $this->legalController()->imprint(new Request('GET', '/impressum'))->body;
        self::assertStringContainsString('Reptilienmarkt GmbH', $body);
    }

    public function testDerAusgelieferteWertWirdNichtAlsAenderungGespeichert(): void
    {
        $service = $this->identityService();

        // Wer den ausgelieferten Wert wieder eintraegt, meint "zurueck auf
        // Anfang" — und bekommt keinen Eintrag.
        $service->save(['anbieter.land' => 'Deutschland'], [], $this->adminId);

        self::assertSame(0, $this->overrides->count());
    }

    public function testEinePflichtangabeDarfNichtGeleertWerden(): void
    {
        $this->expectException(SiteIdentityException::class);
        $this->expectExceptionMessageMatches('/Pflichtangabe/');

        $this->identityService()->save(['anbieter.name' => ''], [], $this->adminId);
    }

    public function testEineUngueltigeEmailWirdAbgewiesen(): void
    {
        $this->expectException(SiteIdentityException::class);

        $this->identityService()->save(['kontakt.email' => 'keine-adresse'], [], $this->adminId);
    }

    public function testEinUebermaessigLangerWertWirdAbgewiesen(): void
    {
        // Kein Impressumsfeld ist einen halben Roman lang. Die Grenze faengt
        // nicht den Tippfehler, sondern das Formular, das jemand umgebaut hat.
        $this->expectException(SiteIdentityException::class);
        $this->expectExceptionMessageMatches('/500 Zeichen/');

        $this->identityService()->save(['anbieter.name' => str_repeat('a', 501)], [], $this->adminId);
    }

    public function testEinUnbekanntesFeldWirdLautAbgewiesen(): void
    {
        // Ein unbekanntes Feld im Formular ist kein Tippfehler, sondern ein
        // manipuliertes Formular.
        $this->expectException(SiteIdentityException::class);

        $this->identityService()->save(['datenbank.passwort' => 'x'], [], $this->adminId);
    }

    public function testZuruecksetzenStelltDenAusgeliefertenStandWiederHer(): void
    {
        $controller = $this->controller($this->user(Role::Admin));

        // Der Schalterstand wird mitgeschickt, wie es das echte Formular tut —
        // nicht angehakte Kontrollkaestchen sendet der Browser nicht mit.
        $controller->save($this->post([
            'feld' => ['anbieter.name' => 'Zwischenstand'],
            'schalter' => ['unvollstaendig' => '1'],
        ]));

        self::assertSame('Zwischenstand', $this->overrides->all()['anbieter.name'] ?? null);

        $controller->save($this->post(['zuruecksetzen' => 'anbieter.name']));

        self::assertArrayNotHasKey('anbieter.name', $this->overrides->all());
    }

    public function testEinSchalterLaesstSichAbwaehlen(): void
    {
        $service = $this->identityService();

        // Ausgeliefert ist "unvollstaendig" = true. Abwaehlen muss den
        // Warnhinweis auf den Rechtsseiten abschalten.
        $service->save([], ['unvollstaendig' => false], $this->adminId);

        self::assertFalse($service->flags()['unvollstaendig']['value']);
    }

    public function testDieAenderungLandetImAuditTrail(): void
    {
        $this->identityService()->save(['anbieter.ort' => 'München'], [], $this->adminId);

        $aktionen = array_column(
            $this->database->select("SELECT action FROM audit_log WHERE entity_type = 'site_identity'"),
            'action',
        );

        self::assertContains('legal.identity_updated', $aktionen);
    }

    public function testDieFehlendenPflichtangabenSchrumpfenMitDenEintraegen(): void
    {
        $service = $this->identityService();
        $vorher = \count($service->identity()->missing());

        $service->save([
            'anbieter.name' => 'Reptilienmarkt GmbH',
            'anbieter.strasse' => 'Musterweg 1',
            'anbieter.plz' => '80331',
            'anbieter.ort' => 'München',
            'kontakt.email' => 'kontakt@example.tld',
            'hosting.anbieter' => 'Beispiel-Hoster',
        ], [], $this->adminId);

        self::assertSame([], $service->identity()->missing());
        self::assertGreaterThan(0, $vorher);
    }

    // ----------------------------------------------------------- Abschnitte

    public function testDieAbschnitteEinerSeiteWerdenGezeigt(): void
    {
        $this->seedSection('seite.datenschutz.30_cookies', 'Cookies', 'Wir setzen ein Cookie.');

        $response = $this->controller($this->user(Role::Admin))->sections(
            new Request('GET', '/admin/recht/abschnitte', ['seite' => 'datenschutz']),
        );

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Cookies', $response->body);
    }

    public function testEinAbschnittWirdGespeichertUndErscheintOeffentlich(): void
    {
        $this->seedSection('seite.datenschutz.30_cookies', 'Cookies', 'Alter Text.');

        $this->controller($this->user(Role::Admin))->saveSection($this->post([
            'seite' => 'datenschutz',
            'schluessel' => 'seite.datenschutz.30_cookies',
            'titel' => 'Cookies',
            'text' => 'Wir setzen **genau ein** Cookie.',
            'aktion' => 'speichern',
        ]));

        $body = $this->legalController()->privacy(new Request('GET', '/datenschutz'))->body;

        self::assertStringContainsString('<strong>genau ein</strong>', $body);
        self::assertStringNotContainsString('Alter Text', $body);
    }

    public function testEinLeererAbschnittWirdAbgewiesen(): void
    {
        $this->seedSection('seite.datenschutz.30_cookies', 'Cookies', 'Text.');

        $this->expectException(LegalPageException::class);
        $this->expectExceptionMessageMatches('/Lösche ihn/');

        $this->pageService()->save('seite.datenschutz.30_cookies', 'Cookies', '', null, $this->adminId);
    }

    public function testEinVerlorenerPlatzhalterWirdAbgewiesen(): void
    {
        // Ohne {hoster} stuende in der Datenschutzerklaerung kein
        // Auftragsverarbeiter mehr — und auffallen wuerde das erst der
        // Aufsichtsbehoerde.
        $this->seedSection('seite.datenschutz.40_empfaenger', 'Empfänger', 'Die Anwendung läuft bei {hoster}.');

        $this->expectException(LegalPageException::class);
        $this->expectExceptionMessageMatches('/\{hoster\}/');

        $this->pageService()->save(
            'seite.datenschutz.40_empfaenger',
            'Empfänger',
            'Die Anwendung läuft irgendwo.',
            null,
            $this->adminId,
        );
    }

    public function testDerPlatzhalterWirdAufDerSeiteEingesetzt(): void
    {
        $this->seedSection('seite.datenschutz.40_empfaenger', 'Empfänger', 'Läuft bei {hoster}.');
        $this->identityService()->save(['hosting.anbieter' => 'Beispiel-Hoster'], [], $this->adminId);

        $body = $this->legalController()->privacy(new Request('GET', '/datenschutz'))->body;

        self::assertStringContainsString('Läuft bei Beispiel-Hoster.', $body);
        self::assertStringNotContainsString('{hoster}', $body);
    }

    public function testSpeichernSetztDasPruefdatum(): void
    {
        $this->seedSection('seite.datenschutz.30_cookies', 'Cookies', 'Text.');

        $this->pageService()->save('seite.datenschutz.30_cookies', 'Cookies', 'Neuer Text.', null, $this->adminId);

        $text = $this->texts->find('seite.datenschutz.30_cookies');

        self::assertNotNull($text);
        self::assertNotNull($text->lastReviewedAt, 'Wer einen Rechtstext aendert, hat ihn damit auch geprueft.');
    }

    public function testAlsGepruefteMarkierenAendertDenTextNicht(): void
    {
        $this->seedSection('seite.datenschutz.30_cookies', 'Cookies', 'Unveränderter Text.');

        $this->pageService()->markReviewed('seite.datenschutz.30_cookies', $this->adminId);

        $text = $this->texts->find('seite.datenschutz.30_cookies');

        self::assertNotNull($text);
        self::assertSame('Unveränderter Text.', $text->body);
        self::assertNotNull($text->lastReviewedAt);
    }

    public function testEingeschleustesMarkupBleibtAufDerSeiteText(): void
    {
        $this->seedSection('seite.datenschutz.30_cookies', 'Cookies', 'Harmlos.');

        $this->pageService()->save(
            'seite.datenschutz.30_cookies',
            'Cookies',
            '<script>alert(1)</script>',
            null,
            $this->adminId,
        );

        $body = $this->legalController()->privacy(new Request('GET', '/datenschutz'))->body;

        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    public function testDieAenderungEinesAbschnittsLandetImAuditTrail(): void
    {
        $this->seedSection('seite.datenschutz.30_cookies', 'Cookies', 'Text.');

        $this->pageService()->save('seite.datenschutz.30_cookies', 'Cookies', 'Neu.', null, $this->adminId);

        $aktionen = array_column(
            $this->database->select("SELECT action FROM audit_log WHERE entity_type = 'legal_text'"),
            'action',
        );

        self::assertContains('legal.section_updated', $aktionen);
    }

    // --------------------------------------------------------------- Helfer

    private function seedSection(string $key, string $title, string $body): void
    {
        $this->texts->insertIfMissing(new LegalText(null, $key, $title, $body));
    }

    private function identityService(): SiteIdentityService
    {
        return new SiteIdentityService(
            $this->config(),
            $this->overrides,
            new PdoAuditLog($this->database),
            $this->clock,
        );
    }

    private function pageService(): LegalPageService
    {
        return new LegalPageService(
            $this->texts,
            new MarkdownRenderer(),
            new PdoAuditLog($this->database),
            $this->clock,
            \dirname(__DIR__, 2) . '/data/legal_texts.json',
        );
    }

    private function legalController(): LegalPageController
    {
        $root = \dirname(__DIR__, 2);

        return new LegalPageController(
            SiteIdentity::merged($this->config(), $this->overrides->all()),
            $this->pageService(),
            TwigFactory::create($root . '/templates', true, null, new Translator($root . '/lang')),
            'https://reptilienmarkt.example',
        );
    }

    private function controller(?User $user): AdminLegalController
    {
        $root = \dirname(__DIR__, 2);
        $translator = new Translator($root . '/lang');

        return new AdminLegalController(
            $this->identityService(),
            $this->pageService(),
            new StubViewer($user),
            $this->session,
            $translator,
            TwigFactory::create($root . '/templates', true, null, $translator),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 2) . '/config/impressum.php';

        return $config;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): Request
    {
        return new Request('POST', '/admin/recht', [], $body + ['_csrf' => $this->csrf]);
    }

    private function user(Role $role): User
    {
        return new User($this->adminId, 'verwaltung@example.tld', 'Verwaltung', $role);
    }
}
