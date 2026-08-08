<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Domain\Species\SpeciesRepository;

/**
 * Macht aus gespeicherten Bloecken ausgabefertige.
 *
 * Hier — und nicht im Template — wird Markdown gerendert und werden Verweise
 * aufgeloest. Ein Template, das selbst Fremdschluessel nachschlaegt, holt bei
 * jeder Schleife eine Abfrage und macht aus einer Seite mit zehn Bloecken zehn
 * Rundreisen zur Datenbank.
 *
 * Ein Verweis, der ins Leere zeigt, laesst den Block nicht verschwinden: Er
 * kommt als "unresolved" zurueck. Fuer den Besucher entfaellt er, fuer den
 * Redakteur steht in der Vorschau, dass hier etwas fehlt.
 */
final readonly class ContentRenderer
{
    public function __construct(
        private MarkdownRenderer $markdown,
        private ListingRepository $listings,
        private SpeciesRepository $species,
        private MediaRepository $media,
    ) {}

    /**
     * @param list<ContentBlock> $blocks
     *
     * @return list<RenderedBlock>
     */
    public function prepare(array $blocks): array
    {
        // Alle Medien der Seite in einer Abfrage. Sonst holt eine Galerie mit
        // zwoelf Bildern zwoelf Mal einzeln nach.
        $mediaIds = [];
        foreach ($blocks as $block) {
            foreach ($block->mediaIds() as $id) {
                $mediaIds[] = $id;
            }
        }

        $media = $mediaIds === [] ? [] : $this->media->findMany($mediaIds);

        $prepared = [];

        foreach ($blocks as $block) {
            $prepared[] = match ($block->type) {
                BlockType::Text => new RenderedBlock(
                    $block->type,
                    $block->position,
                    $block->data,
                    $this->markdown->render($block->string('text')),
                ),
                BlockType::Hinweis, BlockType::Zitat => new RenderedBlock(
                    $block->type,
                    $block->position,
                    $block->data,
                    $this->markdown->render($block->string('text') !== '' ? $block->string('text') : $block->string('zitat')),
                ),
                BlockType::Bild => $this->image($block, $media),
                BlockType::Galerie => $this->gallery($block, $media),
                BlockType::AnzeigenTeaser => $this->listingTeaser($block),
                BlockType::ArtenTeaser => $this->speciesTeaser($block),
                default => new RenderedBlock($block->type, $block->position, $block->data),
            };
        }

        return $prepared;
    }

    /**
     * Reintext aller durchsuchbaren Bloecke — Grundlage von content_search.
     *
     * @param list<ContentBlock> $blocks
     */
    public function plainText(array $blocks): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            $text = $block->searchText();
            if ($text !== '') {
                $parts[] = $this->markdown->toPlainText($text);
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * Der Bildblock: Das Medium wird aufgeloest, der Alternativtext kommt aus
     * dem Block — nicht aus dem Medium.
     *
     * Der Grund ist der Zusammenhang: Dasselbe Foto heisst auf der Artenseite
     * "Ausgewachsenes Weibchen der Bartagame" und im Beitrag ueber Beleuchtung
     * "Terrarium mit UV-Lampe ueber dem Sonnenplatz". Ein Alternativtext am
     * Medium waere fuer beide Faelle falsch. Was am Medium steht, ist der
     * Vorschlag, den der Editor vorbelegt.
     *
     * @param array<int, Media> $media
     */
    private function image(ContentBlock $block, array $media): RenderedBlock
    {
        $bild = $media[$block->int('media_id')] ?? null;

        if ($bild === null) {
            return new RenderedBlock($block->type, $block->position, $block->data, unresolved: true);
        }

        return new RenderedBlock($block->type, $block->position, $block->data, reference: $bild);
    }

    /**
     * @param array<int, Media> $media
     */
    private function gallery(ContentBlock $block, array $media): RenderedBlock
    {
        $bilder = [];

        foreach ($block->mediaIds() as $id) {
            // Ein Bild, das es nicht mehr gibt, faellt aus der Galerie — die
            // uebrigen bleiben. Eine ganze Galerie wegen eines fehlenden Bilds
            // verschwinden zu lassen, waere die schlechtere Antwort.
            if (isset($media[$id])) {
                $bilder[] = $media[$id];
            }
        }

        if ($bilder === []) {
            return new RenderedBlock($block->type, $block->position, $block->data, unresolved: true);
        }

        return new RenderedBlock(
            $block->type,
            $block->position,
            $block->data + ['bilder' => $bilder],
            reference: null,
        );
    }

    private function listingTeaser(ContentBlock $block): RenderedBlock
    {
        $listing = $this->listings->findById($block->int('listing_id'));

        // Nur aktive Anzeigen. Eine pausierte oder abgelaufene Anzeige auf einer
        // redaktionellen Seite zu bewerben, fuehrt Besucher auf eine Seite, die
        // ihnen nichts mehr anbietet.
        if ($listing === null || $listing->status !== ListingStatus::Aktiv) {
            return new RenderedBlock($block->type, $block->position, $block->data, unresolved: true);
        }

        return new RenderedBlock($block->type, $block->position, $block->data, reference: $listing);
    }

    private function speciesTeaser(ContentBlock $block): RenderedBlock
    {
        $slug = $block->string('art_slug');
        $species = $slug === '' ? null : $this->species->findBySlug($slug);

        if ($species === null) {
            return new RenderedBlock($block->type, $block->position, $block->data, unresolved: true);
        }

        return new RenderedBlock($block->type, $block->position, $block->data, reference: $species);
    }
}
