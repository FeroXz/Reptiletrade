<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Content\ContentPermission;
use Reptilienmarkt\Domain\Content\ContentService;
use Reptilienmarkt\Domain\Content\ContentText;
use Reptilienmarkt\Domain\Content\ContentType;
use Reptilienmarkt\Domain\Content\MarkdownRenderer;
use Reptilienmarkt\Domain\Content\MenuItem;
use Reptilienmarkt\Domain\Content\MenuTargetType;
use Reptilienmarkt\Domain\Content\MenuVisibility;
use Reptilienmarkt\Domain\Content\RedirectService;
use Reptilienmarkt\Domain\Content\SeoContext;
use Reptilienmarkt\Domain\Privacy\RetentionPolicy;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\Controller\SitemapController;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\View\ViewContext;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoContentBlockRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentEditorRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentEntryRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentRevisionRepository;
use Reptilienmarkt\Infra\Persistence\PdoConversationRepository;
use Reptilienmarkt\Infra\Persistence\PdoMenuRepository;
use Reptilienmarkt\Infra\Persistence\PdoRedirectRepository;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Infra\Search\Fts5ContentSearchIndex;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;
use Reptilienmarkt\Tests\Support\StubViewer;

/**
 * Sitemap, robots.txt, strukturierte Daten und Menues.
 */
#[CoversClass(SitemapController::class)]
#[CoversClass(SeoContext::class)]
#[CoversClass(ViewContext::class)]
final class SeoDeliveryTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private PdoContentEntryRepository $entries;

    private PdoMenuRepository $menus;

    private int $autor = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-08T10:00:00+00:00'));
        $this->entries = new PdoContentEntryRepository($this->database);
        $this->menus = new PdoMenuRepository($this->database);
        $this->autor = $this->createUser('redaktion@example.tld');
    }

    // -------------------------------------------------------------- Sitemap

    public function testDieSitemapNenntSeitenBeitraegeUndArten(): void
    {
        $this->createSpecies();
        $service = $this->service();

        $seite = $service->create(ContentType::Seite, 'Haltung', null, null, $this->autor);
        $service->publish($seite->id ?? 0, $this->autor);

        $response = $this->sitemap()->sitemap(new Request('GET', '/sitemap.xml'));

        self::assertSame(200, $response->status);

        $xml = @simplexml_load_string($response->body);
        self::assertNotFalse($xml, 'Die Sitemap ist kein gueltiges XML.');

        $adressen = [];
        foreach ($xml->url as $url) {
            $adressen[] = (string) $url->loc;
        }

        self::assertContains('https://reptilienmarkt.example/', $adressen);
        self::assertContains('https://reptilienmarkt.example/markt/', $adressen);
        self::assertContains('https://reptilienmarkt.example/haltung/', $adressen);
        self::assertContains('https://reptilienmarkt.example/art/pogona-vitticeps/', $adressen);
    }

    public function testEntwuerfeUndNoindexStehenNichtInDerSitemap(): void
    {
        $service = $this->service();

        $service->create(ContentType::Seite, 'Entwurf', null, null, $this->autor);

        $intern = $service->create(ContentType::Seite, 'Intern', null, null, $this->autor);
        $service->updateHeader($intern->id ?? 0, ['noindex' => true], $this->autor);
        $service->publish($intern->id ?? 0, $this->autor);

        $body = $this->sitemap()->sitemap(new Request('GET', '/sitemap.xml'))->body;

        self::assertStringNotContainsString('/entwurf/', $body);
        // Sitemap und noindex zugleich ist ein Widerspruch, den Suchmaschinen
        // als Fehler melden.
        self::assertStringNotContainsString('/intern/', $body);
    }

    public function testDieSitemapBeantwortetIfNoneMatchMit304(): void
    {
        $erst = $this->sitemap()->sitemap(new Request('GET', '/sitemap.xml'));
        $etag = (string) ($erst->headers['etag'] ?? '');

        self::assertNotSame('', $etag);

        $zweit = $this->sitemap()->sitemap(
            new Request('GET', '/sitemap.xml', [], [], ['if-none-match' => $etag]),
        );

        self::assertSame(304, $zweit->status);
        self::assertSame('', $zweit->body);
    }

    public function testRobotsVerweistAufDieSitemapUndSperrtDieVerwaltung(): void
    {
        $response = $this->sitemap()->robots(new Request('GET', '/robots.txt'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Sitemap: https://reptilienmarkt.example/sitemap.xml', $response->body);
        self::assertStringContainsString('Disallow: /admin/', $response->body);
        self::assertStringContainsString('Disallow: /vorschau/', $response->body);
    }

    // ------------------------------------------------------ Strukturierte Daten

    public function testEinBeitragBekommtArticleAlsJsonLd(): void
    {
        $service = $this->service();
        $beitrag = $service->create(ContentType::Beitrag, 'Winterruhe richtig planen', null, null, $this->autor);
        $veroeffentlicht = $service->publish($beitrag->id ?? 0, $this->autor);

        $seo = new SeoContext($veroeffentlicht, 'https://reptilienmarkt.example');
        $json = $seo->jsonLd();

        self::assertNotNull($json);

        /** @var array<string, mixed> $daten */
        $daten = json_decode($json, true);

        self::assertSame('Article', $daten['@type']);
        self::assertSame('Winterruhe richtig planen', $daten['headline']);
        self::assertArrayHasKey('datePublished', $daten);
    }

    public function testEineUnterseiteBekommtEineBreadcrumbList(): void
    {
        $service = $this->service();
        $eltern = $service->create(ContentType::Seite, 'Haltung', null, null, $this->autor);
        $kind = $service->create(ContentType::Seite, 'Terrarium', null, $eltern->id, $this->autor);

        $seo = new SeoContext($kind, 'https://reptilienmarkt.example', [$eltern, $kind]);
        $json = $seo->jsonLd();

        self::assertNotNull($json);

        /** @var array<string, mixed> $daten */
        $daten = json_decode($json, true);

        self::assertSame('BreadcrumbList', $daten['@type']);
        self::assertCount(2, $daten['itemListElement']);
    }

    public function testEineWurzelseiteBekommtKeineBreadcrumbList(): void
    {
        // Eine Brotkrumenliste mit einem einzigen Element sagt nichts aus.
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Haltung', null, null, $this->autor);

        self::assertNull((new SeoContext($seite, 'https://reptilienmarkt.example', [$seite]))->jsonLd());
    }

    public function testEinScriptTagImTitelBrichtNichtAusDemJsonLdAus(): void
    {
        $service = $this->service();
        $beitrag = $service->create(ContentType::Beitrag, 'Test </script><script>alert(1)</script>', null, null, $this->autor);
        $veroeffentlicht = $service->publish($beitrag->id ?? 0, $this->autor);

        $json = (new SeoContext($veroeffentlicht, 'https://reptilienmarkt.example'))->jsonLd();

        self::assertNotNull($json);
        // JSON_HEX_TAG macht aus < und > Escapes — der einzige Weg aus einem
        // ld+json-Block heraus ist damit versperrt.
        self::assertStringNotContainsString('</script>', $json);
        self::assertStringNotContainsString('<script>', $json);
    }

    public function testDerEtagAendertSichMitDemInhalt(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Haltung', null, null, $this->autor);
        $vorher = (new SeoContext($seite, 'https://reptilienmarkt.example'))->etag();

        $this->clock->travelTo(new DateTimeImmutable('2026-08-08T11:00:00+00:00'));
        $service->updateHeader($seite->id ?? 0, ['titel' => 'Haltung im Terrarium'], $this->autor);

        $nachher = $this->entries->findById($seite->id ?? 0);
        self::assertNotNull($nachher);

        self::assertNotSame($vorher, (new SeoContext($nachher, 'https://reptilienmarkt.example'))->etag());
    }

    // ---------------------------------------------------------------- Menues

    public function testDieMenuesSindAngelegt(): void
    {
        $menus = $this->menus->menus();

        self::assertArrayHasKey('hauptmenu', $menus);
        self::assertArrayHasKey('fussbereich', $menus);
    }

    public function testEinMenueeintragAufEinenInhaltLoestDenPfadAuf(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Haltung', null, null, $this->autor);
        $service->publish($seite->id ?? 0, $this->autor);

        $this->addItem(MenuTargetType::Entry, (string) ($seite->id ?? 0), 'Haltung');

        $eintraege = $this->context(null)->menu('hauptmenu');

        self::assertCount(1, $eintraege);
        self::assertSame('/haltung/', $eintraege[0]->resolvedPath);
    }

    public function testDerMenuepfadWandertMitDemSlug(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Haltung', null, null, $this->autor);
        $service->publish($seite->id ?? 0, $this->autor);
        $this->addItem(MenuTargetType::Entry, (string) ($seite->id ?? 0), 'Haltung');

        $service->updateHeader($seite->id ?? 0, ['slug' => 'pflege'], $this->autor);

        self::assertSame('/pflege/', $this->context(null)->menu('hauptmenu')[0]->resolvedPath);
    }

    public function testEinEintragAufEinenEntwurfVerschwindet(): void
    {
        $service = $this->service();
        $seite = $service->create(ContentType::Seite, 'Entwurf', null, null, $this->autor);
        $this->addItem(MenuTargetType::Entry, (string) ($seite->id ?? 0), 'Entwurf');

        self::assertSame([], $this->context(null)->menu('hauptmenu'));
    }

    public function testEinEintragInsLeereVerschwindetUndWirdGemeldet(): void
    {
        $this->addItem(MenuTargetType::Entry, '9999', 'Weg');

        self::assertSame([], $this->context(null)->menu('hauptmenu'));

        // Verschwinden heisst nicht verschweigen: bin/doctor.php findet ihn.
        $verwaist = $this->menus->danglingItems();
        self::assertCount(1, $verwaist);
        self::assertSame('Weg', $verwaist[0]['label']);
    }

    public function testDieSichtbarkeitWirdBeachtet(): void
    {
        $this->addItem(MenuTargetType::Route, '/registrieren', 'Registrieren', MenuVisibility::Gast);
        $this->addItem(MenuTargetType::Route, '/konto/', 'Konto', MenuVisibility::Angemeldet);

        $gast = array_map(static fn(MenuItem $i): string => $i->label, $this->context(null)->menu('hauptmenu'));
        $angemeldet = array_map(
            static fn(MenuItem $i): string => $i->label,
            $this->context($this->user())->menu('hauptmenu'),
        );

        self::assertSame(['Registrieren'], $gast);
        self::assertSame(['Konto'], $angemeldet);
    }

    // --------------------------------------------------------------- Helfer

    private function addItem(
        MenuTargetType $type,
        string $value,
        string $label,
        MenuVisibility $visibility = MenuVisibility::Alle,
    ): void {
        $this->menus->saveItem(new MenuItem(
            id: null,
            menuId: $this->menus->menuId('hauptmenu') ?? 0,
            label: $label,
            targetType: $type,
            targetValue: $value,
            visibility: $visibility,
        ));
    }

    private function context(?User $user): ViewContext
    {
        return new ViewContext(
            new StubViewer($user),
            new PdoConversationRepository($this->database),
            new ContentPermission(new PdoContentEditorRepository($this->database)),
            $this->menus,
            $this->entries,
        );
    }

    private function user(): User
    {
        return new User($this->autor, 'redaktion@example.tld', 'Redaktion', Role::Seller);
    }

    private function sitemap(): SitemapController
    {
        return new SitemapController(
            $this->entries,
            new PdoSpeciesRepository($this->database),
            $this->clock,
            'https://reptilienmarkt.example',
        );
    }

    private function service(): ContentService
    {
        return new ContentService(
            $this->entries,
            new PdoContentBlockRepository($this->database),
            new PdoContentRevisionRepository($this->database),
            new Fts5ContentSearchIndex($this->database),
            new ContentText(new MarkdownRenderer()),
            new RedirectService(
                new PdoRedirectRepository($this->database),
                new PdoAuditLog($this->database),
                $this->clock,
            ),
            new RetentionPolicy(require \dirname(__DIR__, 2) . '/config/aufbewahrung.php', $this->clock),
            new PdoAuditLog($this->database),
            $this->clock,
        );
    }
}
