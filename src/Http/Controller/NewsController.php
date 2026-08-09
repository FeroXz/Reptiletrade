<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Content\ContentEntry;
use Reptilienmarkt\Domain\Content\ContentEntryRepository;
use Reptilienmarkt\Domain\Content\ContentSearchIndex;
use Reptilienmarkt\Domain\Content\ContentTermRepository;
use Reptilienmarkt\Domain\Content\ContentType;
use Reptilienmarkt\Domain\Content\Taxonomy;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Twig\Environment;

/**
 * Beitragsuebersicht, Kategoriearchiv und RSS-Feed.
 *
 * Die Einzelseite eines Beitrags liegt beim ContentController: Sie ist eine
 * Inhaltsseite wie jede andere, nur mit einem Pfad, der das Jahr traegt. Eine
 * zweite Ausgabe waere eine zweite Stelle, an der Bloecke gerendert werden.
 */
final readonly class NewsController
{
    private const int PER_PAGE = 12;

    private const int FEED_ITEMS = 20;

    public function __construct(
        private ContentEntryRepository $entries,
        private ContentTermRepository $terms,
        private ContentSearchIndex $search,
        private Environment $twig,
        /** Absolute Adressen im Feed brauchen die eigene Basis — dieselbe Linie wie bei den Mail-Handlern. */
        private string $baseUrl = 'https://example.tld',
    ) {}

    /**
     * GET /news/
     */
    public function index(Request $request): Response
    {
        $seite = max(1, $request->queryInt('seite', 1) ?? 1);
        $suche = $request->queryString('q');

        if ($suche !== null) {
            // Die Suche liefert nach Trefferguete sortiert, nicht nach Datum —
            // wer sucht, will das Passendste, nicht das Neueste.
            $beitraege = $this->byIds($this->search->search($suche, self::PER_PAGE));
            $gesamt = \count($beitraege);
        } else {
            $beitraege = $this->entries->published(ContentType::Beitrag, self::PER_PAGE, ($seite - 1) * self::PER_PAGE);
            $gesamt = $this->entries->countPublished(ContentType::Beitrag);
        }

        return Response::html($this->twig->render('inhalt/news.html.twig', [
            'beitraege' => $beitraege,
            'kategorien' => $this->terms->all(Taxonomy::Kategorie, onlyUsed: true),
            'gesamt' => $gesamt,
            'seite' => $seite,
            'pro_seite' => self::PER_PAGE,
            'suche' => $suche ?? '',
            'kategorie' => null,
        ]));
    }

    /**
     * GET /news/kategorie/{slug}/
     */
    public function category(Request $request): Response
    {
        $slug = $request->attribute('slug');
        $kategorie = $slug === null ? null : $this->terms->findBySlug(Taxonomy::Kategorie, $slug);

        if ($kategorie === null) {
            return Response::html($this->twig->render('fehler/404.html.twig', [
                'meldung' => 'Diese Kategorie gibt es nicht.',
            ]), 404);
        }

        $seite = max(1, $request->queryInt('seite', 1) ?? 1);
        $id = $kategorie->id ?? 0;

        return Response::html($this->twig->render('inhalt/news.html.twig', [
            'beitraege' => $this->byIds($this->terms->entryIds($id, self::PER_PAGE, ($seite - 1) * self::PER_PAGE)),
            'kategorien' => $this->terms->all(Taxonomy::Kategorie, onlyUsed: true),
            'gesamt' => $this->terms->countEntries($id),
            'seite' => $seite,
            'pro_seite' => self::PER_PAGE,
            'suche' => '',
            'kategorie' => $kategorie,
        ]));
    }

    /**
     * GET /feed.xml
     *
     * RSS 2.0. Kein Atom daneben: Zwei Feeds sind zwei Stellen, an denen
     * dasselbe schieflaufen kann, und jeder Leser versteht RSS 2.0.
     */
    public function feed(Request $request): Response
    {
        $beitraege = $this->entries->published(ContentType::Beitrag, self::FEED_ITEMS);
        $basis = rtrim($this->baseUrl, '/');

        $xml = $this->twig->render('inhalt/feed.xml.twig', [
            'beitraege' => $beitraege,
            'basis' => $basis,
            'erzeugt' => gmdate('D, d M Y H:i:s') . ' +0000',
        ]);

        return new Response($xml, 200, [
            'content-type' => 'application/rss+xml; charset=utf-8',
            // Der Feed traegt keine sitzungsgebundenen Angaben — er darf
            // zwischengespeichert werden, anders als die HTML-Seiten.
            'cache-control' => 'public, max-age=900',
        ]);
    }

    /**
     * Holt Eintraege zu einer Liste von IDs und behaelt deren Reihenfolge —
     * bei der Suche ist sie die Trefferreihenfolge und damit die Aussage.
     *
     * @param list<int> $ids
     *
     * @return list<ContentEntry>
     */
    private function byIds(array $ids): array
    {
        $entries = [];

        foreach ($ids as $id) {
            $entry = $this->entries->findById($id);

            if ($entry !== null && $entry->isPublic()) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }
}
