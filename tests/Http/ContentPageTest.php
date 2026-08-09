<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Content\BlockType;
use Reptilienmarkt\Domain\Content\ContentBlock;
use Reptilienmarkt\Domain\Content\ContentEntry;
use Reptilienmarkt\Domain\Content\ContentPath;
use Reptilienmarkt\Domain\Content\ContentRenderer;
use Reptilienmarkt\Domain\Content\ContentStatus;
use Reptilienmarkt\Domain\Content\ContentText;
use Reptilienmarkt\Domain\Content\ContentType;
use Reptilienmarkt\Domain\Content\MarkdownRenderer;
use Reptilienmarkt\Domain\Content\PreviewService;
use Reptilienmarkt\Domain\Content\RedirectService;
use Reptilienmarkt\Http\Controller\ContentController;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\View\TwigFactory;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoContentBlockRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentEntryRepository;
use Reptilienmarkt\Infra\Persistence\PdoListingRepository;
use Reptilienmarkt\Infra\Persistence\PdoMediaRepository;
use Reptilienmarkt\Infra\Persistence\PdoPreviewTokenRepository;
use Reptilienmarkt\Infra\Persistence\PdoRedirectRepository;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

/**
 * Die oeffentliche Auslieferung redaktioneller Seiten.
 */
#[CoversClass(ContentController::class)]
#[CoversClass(ContentRenderer::class)]
final class ContentPageTest extends DatabaseTestCase
{
    private ContentController $controller;

    private PdoContentEntryRepository $entries;

    private PdoContentBlockRepository $blocks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entries = new PdoContentEntryRepository($this->database);
        $this->blocks = new PdoContentBlockRepository($this->database);

        $root = \dirname(__DIR__, 2);
        $clock = new FrozenClock(new DateTimeImmutable('2026-08-08T10:00:00+00:00'));

        $this->controller = new ContentController(
            $this->entries,
            $this->blocks,
            new ContentRenderer(
                new MarkdownRenderer(),
                new ContentText(new MarkdownRenderer()),
                new PdoListingRepository($this->database),
                new PdoSpeciesRepository($this->database),
                new PdoMediaRepository($this->database),
            ),
            new PreviewService(new PdoPreviewTokenRepository($this->database), $clock),
            new RedirectService(new PdoRedirectRepository($this->database), new PdoAuditLog($this->database), $clock),
            new PdoMediaRepository($this->database),
            TwigFactory::create($root . '/templates', true, null, new Translator($root . '/lang')),
            'https://reptilienmarkt.example',
        );
    }

    public function testVeroeffentlichteSeiteWirdAusgeliefert(): void
    {
        $id = $this->save('haltung', 'Haltung im Terrarium', ContentStatus::Veroeffentlicht);
        $this->blocks->replaceAll($id, [
            new ContentBlock(null, BlockType::Text, 0, ['text' => "## Grundlagen\n\nEin **wichtiger** Satz."]),
        ]);

        $response = $this->controller->show($this->request('haltung/'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('<h1 class="text-2xl font-semibold">Haltung im Terrarium</h1>', $response->body);
        self::assertStringContainsString('<h2>Grundlagen</h2>', $response->body);
        self::assertStringContainsString('<strong>wichtiger</strong>', $response->body);
        // Kanonisch ist absolut: Eine relative Angabe laesst offen, ob
        // https://beispiel.tld/haltung/ und https://www.beispiel.tld/haltung/
        // dieselbe Seite sind.
        self::assertStringContainsString(
            '<link rel="canonical" href="https://reptilienmarkt.example/haltung/">',
            $response->body,
        );
    }

    public function testEntwurfIstOeffentlichNichtErreichbar(): void
    {
        $this->save('haltung', 'Haltung', ContentStatus::Entwurf);

        self::assertSame(404, $this->controller->show($this->request('haltung/'))->status);
    }

    public function testGeplanterEintragIstNochNichtErreichbar(): void
    {
        // Auch dann nicht, wenn der Termin verstrichen ist: Freigeschaltet wird
        // ueber den Auftrag content.publish, nicht durch einen zufaelligen Aufruf.
        $this->save(
            'haltung',
            'Haltung',
            ContentStatus::Geplant,
            new DateTimeImmutable('2020-01-01T00:00:00Z'),
        );

        self::assertSame(404, $this->controller->show($this->request('haltung/'))->status);
    }

    public function testArchivierteSeiteAntwortetMit410(): void
    {
        $this->save('alt', 'Alte Seite', ContentStatus::Archiviert);

        $response = $this->controller->show($this->request('alt/'));

        self::assertSame(410, $response->status);
        self::assertStringContainsString('noindex', $response->body);
    }

    public function testUnbekannterPfadAntwortetMit404(): void
    {
        self::assertSame(404, $this->controller->show($this->request('gibt-es-nicht/'))->status);
    }

    public function testPfadWirdNormalisiert(): void
    {
        $this->save('haltung', 'Haltung', ContentStatus::Veroeffentlicht);

        // Ohne abschliessenden Schraegstrich, mit doppeltem — beides derselbe
        // Inhalt. Sonst gaebe es drei Adressen fuer eine Seite.
        self::assertSame(200, $this->controller->show($this->request('haltung'))->status);
        self::assertSame(200, $this->controller->show($this->request('haltung//'))->status);
    }

    public function testUnterseiteZeigtDiePfadleiste(): void
    {
        $eltern = $this->save('haltung', 'Haltung', ContentStatus::Veroeffentlicht);
        $this->entries->save(new ContentEntry(
            id: null,
            type: ContentType::Seite,
            slug: 'terrarium',
            path: '/haltung/terrarium/',
            title: 'Terrarium',
            status: ContentStatus::Veroeffentlicht,
            parentId: $eltern,
            publishedAt: new DateTimeImmutable('2026-01-01T09:00:00Z'),
            createdAt: new DateTimeImmutable('2026-01-01T09:00:00Z'),
            updatedAt: new DateTimeImmutable('2026-01-01T09:00:00Z'),
        ));

        $body = $this->controller->show($this->request('haltung/terrarium/'))->body;

        self::assertStringContainsString('href="/haltung/"', $body);
        self::assertStringContainsString('aria-current="page"', $body);
    }

    public function testEingeschleustesMarkupBleibtText(): void
    {
        $id = $this->save('haltung', 'Haltung', ContentStatus::Veroeffentlicht);
        $this->blocks->replaceAll($id, [
            new ContentBlock(null, BlockType::Text, 0, ['text' => '<script>alert(1)</script>']),
        ]);

        $body = $this->controller->show($this->request('haltung/'))->body;

        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    public function testTitelUndAnrissWerdenEscapet(): void
    {
        $this->entries->save(new ContentEntry(
            id: null,
            type: ContentType::Seite,
            slug: 'gefaehrlich',
            path: '/gefaehrlich/',
            title: '<script>alert(1)</script>',
            status: ContentStatus::Veroeffentlicht,
            excerpt: '<img src=x onerror=alert(1)>',
            publishedAt: new DateTimeImmutable('2026-01-01T09:00:00Z'),
            createdAt: new DateTimeImmutable('2026-01-01T09:00:00Z'),
            updatedAt: new DateTimeImmutable('2026-01-01T09:00:00Z'),
        ));

        $body = $this->controller->show($this->request('gefaehrlich/'))->body;

        self::assertStringNotContainsString('<script>', $body);
        self::assertStringNotContainsString('<img src=x', $body);
    }

    public function testNoindexSetztDasMetaTag(): void
    {
        $this->entries->save(new ContentEntry(
            id: null,
            type: ContentType::Seite,
            slug: 'intern',
            path: '/intern/',
            title: 'Intern',
            status: ContentStatus::Veroeffentlicht,
            noindex: true,
            publishedAt: new DateTimeImmutable('2026-01-01T09:00:00Z'),
            createdAt: new DateTimeImmutable('2026-01-01T09:00:00Z'),
            updatedAt: new DateTimeImmutable('2026-01-01T09:00:00Z'),
        ));

        self::assertStringContainsString(
            '<meta name="robots" content="noindex, follow">',
            $this->controller->show($this->request('intern/'))->body,
        );
    }

    public function testVerweisInsLeereLaesstDieSeiteStehen(): void
    {
        $id = $this->save('haltung', 'Haltung', ContentStatus::Veroeffentlicht);
        $this->blocks->replaceAll($id, [
            new ContentBlock(null, BlockType::Text, 0, ['text' => 'Bleibt sichtbar.']),
            new ContentBlock(null, BlockType::ArtenTeaser, 1, ['art_slug' => 'gibt-es-nicht']),
        ]);

        $response = $this->controller->show($this->request('haltung/'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Bleibt sichtbar.', $response->body);
    }

    public function testArtenTeaserWirdAufgeloest(): void
    {
        $this->createSpecies();
        $id = $this->save('haltung', 'Haltung', ContentStatus::Veroeffentlicht);
        $this->blocks->replaceAll($id, [
            new ContentBlock(null, BlockType::ArtenTeaser, 0, ['art_slug' => 'pogona-vitticeps']),
        ]);

        $body = $this->controller->show($this->request('haltung/'))->body;

        self::assertStringContainsString('href="/art/pogona-vitticeps/"', $body);
        self::assertStringContainsString('Pogona vitticeps', $body);
    }

    public function testEinAlterPfadWirdWeitergeleitet(): void
    {
        $this->redirects()->create('/alter-pfad/', '/haltung/', null);

        $response = $this->controller->show($this->request('alter-pfad/'));

        self::assertSame(301, $response->status);
        self::assertSame('/haltung/', $response->headers['location'] ?? '');
    }

    public function testEinArchivierterInhaltMitWeiterleitungLeitetWeiter(): void
    {
        // Die Weiterleitung schlaegt die 410: Wer sagt, wohin es weitergeht,
        // meint nicht "endgueltig weg".
        $this->save('alt', 'Alte Seite', ContentStatus::Archiviert);
        $this->redirects()->create('/alt/', '/haltung/', null);

        $response = $this->controller->show($this->request('alt/'));

        self::assertSame(301, $response->status);
    }

    public function testEineUnveraenderteSeiteAntwortetMit304(): void
    {
        $this->save('haltung', 'Haltung', ContentStatus::Veroeffentlicht);

        $erst = $this->controller->show($this->request('haltung/'));
        $etag = (string) ($erst->headers['etag'] ?? '');

        self::assertNotSame('', $etag);

        $zweit = $this->controller->show(
            (new Request('GET', '/haltung/', [], [], ['if-none-match' => $etag]))
                ->withAttributes(['pfad' => 'haltung/']),
        );

        self::assertSame(304, $zweit->status);
        self::assertSame('', $zweit->body);
    }

    public function testInhaltsseitenBleibenPrivatZwischengespeichert(): void
    {
        // Die Kopfzeile zeigt den angemeldeten Namen — ein vorgelagerter
        // Zwischenspeicher darf die Seite nicht weiterreichen.
        $this->save('haltung', 'Haltung', ContentStatus::Veroeffentlicht);

        $response = $this->controller->show($this->request('haltung/'));

        self::assertStringContainsString('private', (string) ($response->headers['cache-control'] ?? ''));
    }

    private function redirects(): RedirectService
    {
        return new RedirectService(
            new PdoRedirectRepository($this->database),
            new PdoAuditLog($this->database),
            new FrozenClock(new DateTimeImmutable('2026-08-08T10:00:00+00:00')),
        );
    }

    private function save(
        string $slug,
        string $title,
        ContentStatus $status,
        ?DateTimeImmutable $publishedAt = null,
    ): int {
        $moment = new DateTimeImmutable('2026-01-01T09:00:00Z');

        return $this->entries->save(new ContentEntry(
            id: null,
            type: ContentType::Seite,
            slug: $slug,
            path: ContentPath::forPage($slug),
            title: $title,
            status: $status,
            publishedAt: $status->requiresPublishedAt() ? ($publishedAt ?? $moment) : $publishedAt,
            createdAt: $moment,
            updatedAt: $moment,
        ));
    }

    private function request(string $pfad): Request
    {
        return (new Request('GET', '/' . $pfad))->withAttributes(['pfad' => $pfad]);
    }
}
