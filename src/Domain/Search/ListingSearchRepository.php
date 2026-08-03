<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

/**
 * Persistenzgrenze der Suche. Die Domain uebergibt Kriterien, keine Query-Strings —
 * die Umsetzung auf FTS5 bzw. spaeter tsvector bleibt in src/Infra.
 */
interface ListingSearchRepository
{
    public function search(SearchCriteria $criteria): SearchResult;

    public function count(SearchCriteria $criteria): int;

    /**
     * Merkmale, die in der aktuellen Treffermenge ueberhaupt vorkommen —
     * die Grundlage der Morph-Facette.
     *
     * @return list<array{morph_id: int, name: string, zygosity: string, anzahl: int}>
     */
    public function morphFacet(SearchCriteria $criteria, int $limit = 40): array;
}
