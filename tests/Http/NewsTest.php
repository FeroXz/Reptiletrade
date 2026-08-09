<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Content\BlockType;
use Reptilienmarkt\Domain\Content\ContentBlock;
use Reptilienmarkt\Domain\Content\ContentEntry;
use Reptilienmarkt\Domain\Content\ContentService;
use Reptilienmarkt\Domain\Content\ContentText;
use Reptilienmarkt\Domain\Content\ContentType;
use Reptilienmarkt\Domain\Content\MarkdownRenderer;
use Reptilienmarkt\Domain\Content\RedirectService;
use Reptilienmarkt\Domain\Content\Taxonomy;
use Reptilienmarkt\Domain\Privacy\RetentionPolicy;
use Reptilienmarkt\Http\Controller\NewsController;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\View\TwigFactory;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoContentBlockRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentEntryRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentRevisionRepository;
use Reptilienmarkt\Infra\Persistence\PdoContentTermRepository;
use Reptilienmarkt\Infra\Persistence\PdoRedirectRepository;
use Reptilienmarkt\Infra\Search\ContentIndexer;
use Reptilienmarkt\Infra\Search\Fts5ContentSearchIndex;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

/**
 * Beitragsuebersicht, Kategoriearchiv, Volltextsuche und Feed.
 */
#[CoversClass(NewsController::class)]
#[CoversClass(Fts5ContentSearchIndex::class)]
#[CoversClass(ContentIndexer::class)]
final class NewsTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private PdoContentEntryRepository $entries;

    private PdoContentBlockRepository $blocks;

    private PdoContentTermRepository $terms;

    private Fts5ContentSearchIndex $index;

    private int $autor = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-08T10:00:00+00:00'));
        $this->entries = new PdoContentEntryRepository($this->database);
        $this->blocks = new PdoContentBlockRepository($this->database);
        $this->terms = new PdoContentTermRepository($this->database);
        $this->index = new Fts5ContentSearchIndex($this->database);
        $this->autor = $this->createUser('redaktion@example.tld');
    }

    // ------------------------------------------------------------ Uebersicht

    public function testDieUebersichtZeigtNurVeroeffentlichtes(): void
    {
        $this->post('Erster Beitrag', veroeffentlichen: true);
        $this->post('Noch ein Entwurf', veroeffentlichen: false);

        $body = $this->controller()->index(new Request('GET', '/news/'))->body;

        self::assertStringContainsString('Erster Beitrag', $body);
        self::assertStringNotContainsString('Noch ein Entwurf', $body);
    }

    public function testDieUebersichtSortiertNeuesteZuerst(): void
    {
        $this->post('Alter Beitrag', veroeffentlichen: true, wann: new DateTimeImmutable('2026-01-01T09:00:00Z'));
        $this->post('Neuer Beitrag', veroeffentlichen: true, wann: new DateTimeImmutable('2026-07-01T09:00:00Z'));

        $body = $this->controller()->index(new Request('GET', '/news/'))->body;

        self::assertLessThan(strpos($body, 'Alter Beitrag'), (int) strpos($body, 'Neuer Beitrag'));
    }

    // ------------------------------------------------------------- Kategorie

    public function testDasKategoriearchivZeigtNurSeineBeitraege(): void
    {
        $drin = $this->post('Ueber Winterruhe', veroeffentlichen: true);
        $this->post('Etwas anderes', veroeffentlichen: true);

        $kategorie = $this->terms->ensure(Taxonomy::Kategorie, 'Haltung');
        $this->terms->assign($drin, [$kategorie->id ?? 0]);

        $request = (new Request('GET', '/news/kategorie/haltung/'))->withAttributes(['slug' => 'haltung']);
        $body = $this->controller()->category($request)->body;

        self::assertStringContainsString('Ueber Winterruhe', $body);
        self::assertStringNotContainsString('Etwas anderes', $body);
    }

    public function testEineUnbekannteKategorieAntwortetMit404(): void
    {
        $request = (new Request('GET', '/news/kategorie/gibt-es-nicht/'))->withAttributes(['slug' => 'gibt-es-nicht']);

        self::assertSame(404, $this->controller()->category($request)->status);
    }

    public function testEineLeereKategorieStehtNichtInDerNavigation(): void
    {
        // Eine Kategorie, deren Archiv leer ist, waere ein Verweis ins Nichts.
        $this->terms->ensure(Taxonomy::Kategorie, 'Ungenutzt');
        $this->post('Irgendwas', veroeffentlichen: true);

        $body = $this->controller()->index(new Request('GET', '/news/'))->body;

        self::assertStringNotContainsString('Ungenutzt', $body);
    }

    public function testEineKategorieEntstehtBeimZuordnen(): void
    {
        $erst = $this->terms->ensure(Taxonomy::Kategorie, 'Haltung');
        $zweit = $this->terms->ensure(Taxonomy::Kategorie, 'Haltung');

        self::assertSame($erst->id, $zweit->id);
        self::assertSame('haltung', $erst->slug);
    }

    // ----------------------------------------------------------------- Suche

    public function testDieSucheFindetWorteAusDemRumpf(): void
    {
        $id = $this->post('Ein unauffaelliger Titel', veroeffentlichen: false);

        $this->service()->saveBlocks($id, [
            new ContentBlock(null, BlockType::Text, 0, ['text' => 'Die **Winterruhe** dauert bei dieser Art mehrere Wochen.']),
        ], $this->autor);
        $this->service()->publish($id, $this->autor);

        $treffer = $this->index->search('Winterruhe');

        self::assertSame([$id], $treffer);

        $request = new Request('GET', '/news/', ['q' => 'Winterruhe']);
        self::assertStringContainsString('Ein unauffaelliger Titel', $this->controller()->index($request)->body);
    }

    public function testDerIndexTraegtKeineEntwuerfe(): void
    {
        $id = $this->post('Geheimes Vorhaben', veroeffentlichen: true);
        $this->service()->saveBlocks($id, [
            new ContentBlock(null, BlockType::Text, 0, ['text' => 'Ein besonderes Kennwort: Zwergbartagame.']),
        ], $this->autor);

        self::assertSame([$id], $this->index->search('Zwergbartagame'));

        // Zuruecknehmen nimmt den Beitrag auch aus der Suche — ein Entwurf, den
        // die Suche findet, ist kein Entwurf mehr.
        $this->service()->unpublish($id, $this->autor);

        self::assertSame([], $this->index->search('Zwergbartagame'));
    }

    public function testDerIndexVergisstGeloeschteEintraege(): void
    {
        $id = $this->post('Wird geloescht', veroeffentlichen: true);
        $this->service()->saveBlocks($id, [
            new ContentBlock(null, BlockType::Text, 0, ['text' => 'Merkwort Taggecko.']),
        ], $this->autor);

        self::assertNotSame([], $this->index->search('Taggecko'));

        $this->service()->delete($id, $this->autor);

        self::assertSame([], $this->index->search('Taggecko'));
        self::assertSame(0, $this->index->count());
    }

    public function testDerNeuaufbauStelltDenIndexWiederHer(): void
    {
        $id = $this->post('Beitrag', veroeffentlichen: true);
        $this->service()->saveBlocks($id, [
            new ContentBlock(null, BlockType::Text, 0, ['text' => 'Merkwort Kornnatter.']),
        ], $this->autor);

        // Index von Hand leeren — der Fall, fuer den es bin/reindex.php gibt.
        $this->database->execute('DELETE FROM content_search');
        self::assertSame(0, $this->index->count());

        $indiziert = $this->indexer()->rebuildAll();

        self::assertSame(1, $indiziert);
        self::assertSame([$id], $this->index->search('Kornnatter'));
    }

    public function testEineFtsSyntaxAusDerEingabeBrichtNichtsAb(): void
    {
        $this->post('Beitrag', veroeffentlichen: true);

        // Ein Sternchen oder ein Anfuehrungszeichen wuerde die Abfrage sonst
        // umdeuten oder mit einem Syntaxfehler abbrechen.
        self::assertSame([], $this->index->search('"* OR *"'));
        self::assertSame([], $this->index->search('NEAR('));
    }

    // ------------------------------------------------------------------ Feed

    public function testDerFeedIstGueltigesXmlUndNenntDieBeitraege(): void
    {
        $this->post('Erster Beitrag', veroeffentlichen: true);

        $response = $this->controller()->feed(new Request('GET', '/feed.xml'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('application/rss+xml', (string) ($response->headers['content-type'] ?? ''));

        $xml = @simplexml_load_string($response->body);

        self::assertNotFalse($xml, 'Der Feed ist kein gueltiges XML.');
        self::assertSame('2.0', (string) $xml['version']);
        self::assertSame('Erster Beitrag', (string) $xml->channel->item[0]->title);
        self::assertStringStartsWith('https://reptilienmarkt.example/news/', (string) $xml->channel->item[0]->link);
    }

    public function testDerFeedNenntSeineEigeneAdresse(): void
    {
        $this->post('Beitrag', veroeffentlichen: true);

        $xml = @simplexml_load_string($this->controller()->feed(new Request('GET', '/feed.xml'))->body);

        self::assertNotFalse($xml);

        // Attribute eines Elements aus einem fremden Namensraum liest
        // SimpleXML nur ueber attributes() — der Index-Zugriff liefert dort
        // eine leere Zeichenkette.
        $atom = $xml->channel->children('http://www.w3.org/2005/Atom')->link->attributes();

        self::assertNotNull($atom);
        self::assertSame('https://reptilienmarkt.example/feed.xml', (string) $atom->href);
        self::assertSame('self', (string) $atom->rel);
    }

    public function testSonderzeichenBrechenDenFeedNicht(): void
    {
        $id = $this->post('Fisch & Co. <script>', veroeffentlichen: false);
        $this->service()->updateHeader($id, ['anriss' => 'Ein "Zitat" & mehr'], $this->autor);
        $this->service()->publish($id, $this->autor);

        $response = $this->controller()->feed(new Request('GET', '/feed.xml'));
        $xml = @simplexml_load_string($response->body);

        self::assertNotFalse($xml, 'Der Feed ist kein gueltiges XML.');
        self::assertSame('Fisch & Co. <script>', (string) $xml->channel->item[0]->title);
    }

    public function testDerFeedDarfZwischengespeichertWerden(): void
    {
        // Er traegt keine sitzungsgebundenen Angaben — anders als HTML-Seiten.
        $response = $this->controller()->feed(new Request('GET', '/feed.xml'));

        self::assertStringContainsString('public', (string) ($response->headers['cache-control'] ?? ''));
    }

    // --------------------------------------------------------------- Helfer

    private function post(string $titel, bool $veroeffentlichen, ?DateTimeImmutable $wann = null): int
    {
        $service = $this->service();
        $beitrag = $service->create(ContentType::Beitrag, $titel, null, null, $this->autor);
        $id = $beitrag->id ?? 0;

        if ($veroeffentlichen) {
            $service->publish($id, $this->autor, $wann);
        }

        return $id;
    }

    private function controller(): NewsController
    {
        $root = \dirname(__DIR__, 2);

        return new NewsController(
            $this->entries,
            $this->terms,
            $this->index,
            TwigFactory::create($root . '/templates', true, null, new Translator($root . '/lang')),
            'https://reptilienmarkt.example',
        );
    }

    private function indexer(): ContentIndexer
    {
        return new ContentIndexer(
            $this->entries,
            $this->blocks,
            new ContentText(new MarkdownRenderer()),
            $this->index,
        );
    }

    private function service(): ContentService
    {
        return new ContentService(
            $this->entries,
            $this->blocks,
            new PdoContentRevisionRepository($this->database),
            $this->index,
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

    /**
     * Nur, damit ein Beitrag ueberhaupt einen Pfad hat, an dem der Feed
     * hängt — die Entitaet selbst wird hier nicht geprueft.
     */
    public function testEinBeitragLiegtUnterNews(): void
    {
        $id = $this->post('Beitrag', veroeffentlichen: true);
        $entry = $this->entries->findById($id);

        self::assertInstanceOf(ContentEntry::class, $entry);
        self::assertStringStartsWith('/news/', $entry->path);
    }
}
