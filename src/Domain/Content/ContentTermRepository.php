<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

interface ContentTermRepository
{
    public function findById(int $id): ?ContentTerm;

    public function findBySlug(Taxonomy $taxonomy, string $slug): ?ContentTerm;

    /**
     * Legt an oder liefert den vorhandenen Begriff — Kategorien entstehen beim
     * Zuordnen, nicht in einer eigenen Verwaltung. Eine leere Kategorienliste,
     * die erst gepflegt werden muss, bevor der erste Beitrag eine bekommt,
     * haelt niemanden auf, sondern nur auf.
     */
    public function ensure(Taxonomy $taxonomy, string $name): ContentTerm;

    /**
     * @return list<ContentTerm> mit entryCount
     */
    public function all(Taxonomy $taxonomy, bool $onlyUsed = false): array;

    /**
     * @return list<ContentTerm>
     */
    public function forEntry(int $entryId): array;

    /**
     * @param list<int> $termIds
     */
    public function assign(int $entryId, array $termIds): void;

    /**
     * Die IDs veroeffentlichter Eintraege einer Kategorie, neueste zuerst.
     *
     * @return list<int>
     */
    public function entryIds(int $termId, int $limit, int $offset = 0): array;

    public function countEntries(int $termId): int;
}
