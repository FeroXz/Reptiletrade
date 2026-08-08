<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

/**
 * Volltext ueber die Inhalte — hinter einer Schnittstelle, wie beim
 * Anzeigenindex: SQLite nutzt FTS5, eine spaetere PostgreSQL-Variante
 * tsvector. Kein Aufrufer sieht MATCH.
 */
interface ContentSearchIndex
{
    public function index(int $entryId, string $title, string $excerpt, string $bodyText): void;

    public function remove(int $entryId): void;

    /**
     * Die IDs der Treffer, beste zuerst.
     *
     * @return list<int>
     */
    public function search(string $query, int $limit = 20): array;

    public function count(): int;

    public function optimize(): void;
}
