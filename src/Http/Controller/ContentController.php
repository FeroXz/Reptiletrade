<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Content\ContentBlockRepository;
use Reptilienmarkt\Domain\Content\ContentEntry;
use Reptilienmarkt\Domain\Content\ContentEntryRepository;
use Reptilienmarkt\Domain\Content\ContentPath;
use Reptilienmarkt\Domain\Content\ContentRenderer;
use Reptilienmarkt\Domain\Content\MediaRepository;
use Reptilienmarkt\Domain\Content\PreviewService;
use Reptilienmarkt\Domain\Content\RedirectService;
use Reptilienmarkt\Domain\Content\SeoContext;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Twig\Environment;

/**
 * Liefert die redaktionellen Seiten aus.
 *
 * Die Route steht als letzte im Router: Alles, was die Anwendung selbst
 * beansprucht, hat vorher gegriffen. Damit ein Redakteur nicht in dieses
 * Schweigen hineinspeichert, prueft ReservedPaths beim Anlegen — und ein Test
 * haelt die Liste gegen die tatsaechlich registrierten Routen.
 */
final readonly class ContentController
{
    public function __construct(
        private ContentEntryRepository $entries,
        private ContentBlockRepository $blocks,
        private ContentRenderer $renderer,
        private PreviewService $previews,
        private RedirectService $redirects,
        private MediaRepository $media,
        private Environment $twig,
        private string $baseUrl = 'https://example.tld',
    ) {}

    public function show(Request $request): Response
    {
        $pfad = $request->attribute('pfad');

        if ($pfad === null || $pfad === '') {
            return $this->notFound();
        }

        $normalisiert = ContentPath::normalize($pfad);
        $entry = $this->entries->findByPath($normalisiert);

        if ($entry === null) {
            // Erst die Weiterleitungen fragen, dann aufgeben: Genau dafuer
            // gibt es sie.
            $redirect = $this->redirects->resolve($normalisiert);

            return $redirect === null
                ? $this->notFound()
                : Response::redirect($redirect->toPath, $redirect->code);
        }

        if ($entry->status->isGone()) {
            // Auch ein archivierter Inhalt kann weitergeleitet worden sein —
            // dann gilt die Weiterleitung, nicht die 410.
            $redirect = $this->redirects->resolve($normalisiert);

            if ($redirect !== null) {
                return Response::redirect($redirect->toPath, $redirect->code);
            }

            // 410 statt 404: Ein bewusst entfernter Inhalt ist etwas anderes
            // als ein Tippfehler in der URL. Suchmaschinen nehmen die Adresse
            // daraufhin dauerhaft aus dem Bestand, statt sie monatelang weiter
            // abzufragen.
            return Response::html($this->twig->render('inhalt/entfernt.html.twig', [
                'eintrag' => $entry,
            ]), 410);
        }

        if (!$entry->isPublic()) {
            return $this->notFound();
        }

        $response = $this->render($entry);
        $etag = $this->seo($entry)->etag();

        // Ein unveraenderter Inhalt braucht nicht noch einmal uebertragen zu
        // werden. Die Seite bleibt trotzdem "private": Die Kopfzeile zeigt den
        // angemeldeten Namen, und ein vorgelagerter Zwischenspeicher duerfte
        // sie deshalb nicht an den naechsten Besucher weiterreichen.
        if (($request->headers['if-none-match'] ?? '') === $etag) {
            return new Response('', 304, ['etag' => $etag, 'cache-control' => 'private, must-revalidate']);
        }

        return $response
            ->withHeader('etag', $etag)
            ->withHeader('cache-control', 'private, must-revalidate')
            ->withHeader('x-robots-tag', $entry->noindex ? 'noindex, follow' : 'all');
    }

    /**
     * GET /vorschau/{token}
     *
     * Zeigt einen Entwurf, ohne dass sich der Betrachter anmelden muss. Die
     * Antwort traegt noindex und keinen Zwischenspeicher: Ein Vorschaulink, der
     * in einem Suchindex landet, verraet den Entwurf allen.
     */
    public function preview(Request $request): Response
    {
        $token = $request->attribute('token');
        $entryId = $token === null ? null : $this->previews->resolve($token);

        // Unbekannt und abgelaufen ergeben dieselbe Antwort: Wer probiert, soll
        // nicht erfahren, ob er einen echten Link erwischt hat, der nur zu spaet
        // kam.
        $entry = $entryId === null ? null : $this->entries->findById($entryId);

        if ($entry === null) {
            return $this->notFound();
        }

        return $this->render($entry, preview: true)
            ->withHeader('x-robots-tag', 'noindex, nofollow')
            ->withHeader('cache-control', 'private, no-store');
    }

    /**
     * Rendert einen Eintrag mit seinen Bloecken. Auch die Vorschau (Paket 10.4)
     * geht hierdurch — sonst zeigte sie etwas anderes als die spaetere Seite.
     */
    public function render(ContentEntry $entry, bool $preview = false): Response
    {
        $blocks = $this->blocks->forEntry($entry->id ?? 0);

        return Response::html($this->twig->render($entry->template->templateFile(), [
            'eintrag' => $entry,
            'bloecke' => $this->renderer->prepare($blocks),
            'pfadleiste' => $this->breadcrumb($entry),
            'seo' => $this->seo($entry),
            'vorschau' => $preview,
        ]));
    }

    private function seo(ContentEntry $entry): SeoContext
    {
        return new SeoContext(
            $entry,
            $this->baseUrl,
            $this->breadcrumb($entry),
            $entry->ogImageId === null ? null : $this->media->findById($entry->ogImageId),
        );
    }

    /**
     * Die Ahnenreihe einer Seite, von der Wurzel abwaerts — Grundlage der
     * Brotkrumen und des JSON-LD in Paket 10.7.
     *
     * @return list<ContentEntry>
     */
    private function breadcrumb(ContentEntry $entry): array
    {
        $chain = [];
        $current = $entry;

        // Die Kette ist durch den Baum begrenzt; die Schranke faengt trotzdem
        // ab, was ein von Hand verbogener parent_id anrichten koennte.
        for ($tiefe = 0; $tiefe < 10; ++$tiefe) {
            array_unshift($chain, $current);

            if ($current->parentId === null) {
                break;
            }

            $parent = $this->entries->findById($current->parentId);
            if ($parent === null) {
                break;
            }

            $current = $parent;
        }

        return $chain;
    }

    private function notFound(): Response
    {
        return Response::html($this->twig->render('fehler/404.html.twig', [
            'meldung' => 'Diese Seite gibt es nicht.',
        ]), 404);
    }
}
