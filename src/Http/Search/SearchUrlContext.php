<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Search;

use Reptilienmarkt\Domain\Species\Species;

/**
 * Was der URL-Bauer braucht, um SEO-Pfade zu erzeugen: die aufgeloeste Art,
 * die Slugs der Merkmale und die Region. Einmal je Anfrage zusammengestellt,
 * damit jeder Facettenlink ohne weitere Datenbankabfrage entsteht.
 */
final readonly class SearchUrlContext
{
    /**
     * @param array<int, string> $morphSlugs Merkmals-ID -> Slug
     */
    public function __construct(
        public ?Species $species = null,
        public array $morphSlugs = [],
        public ?string $regionSlug = null,
    ) {}

    public function speciesSegment(): ?string
    {
        return $this->species?->commonSlug;
    }

    /**
     * @param list<int> $morphIds
     */
    public function morphSegment(array $morphIds): ?string
    {
        $slugs = [];
        foreach ($morphIds as $id) {
            $slug = $this->morphSlugs[$id] ?? null;
            if ($slug === null) {
                // Unbekanntes Merkmal: lieber Query-Parameter als ein Pfad,
                // der nicht wieder auf dieselben Filter zurueckfuehrt.
                return null;
            }
            $slugs[] = $slug;
        }

        return $slugs === [] ? null : implode('-', $slugs);
    }
}
