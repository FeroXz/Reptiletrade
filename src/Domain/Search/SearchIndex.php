<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

/**
 * Volltextindex hinter einer Schnittstelle: SQLite nutzt FTS5, eine spaetere
 * PostgreSQL-Variante tsvector. Kein Aufrufer sieht MATCH.
 */
interface SearchIndex
{
    public function index(IndexDocument $document): void;

    public function remove(int $listingId): void;

    /**
     * Baut den Index vollstaendig neu auf.
     *
     * @param iterable<IndexDocument> $documents
     *
     * @return int Anzahl indizierter Anzeigen
     */
    public function rebuild(iterable $documents): int;

    public function count(): int;

    /**
     * Fasst den Index zusammen — nach einem Neuaufbau sinnvoll.
     */
    public function optimize(): void;
}
