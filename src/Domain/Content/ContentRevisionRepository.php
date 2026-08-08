<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use DateTimeImmutable;

interface ContentRevisionRepository
{
    /**
     * Legt die naechste Fassung an und liefert ihre Nummer.
     *
     * @param array<string, mixed> $snapshot
     */
    public function append(
        int $entryId,
        array $snapshot,
        ?int $authorId,
        string $comment,
        DateTimeImmutable $moment,
    ): int;

    public function find(int $entryId, int $revisionNo): ?ContentRevision;

    /**
     * Neueste zuerst.
     *
     * @return list<ContentRevision>
     */
    public function forEntry(int $entryId, int $limit = 50): array;

    /**
     * Behaelt die juengsten Fassungen und wirft den Rest weg.
     *
     * @return int Anzahl der entfernten Fassungen
     */
    public function prune(int $entryId, int $keep): int;

    public function count(int $entryId): int;
}
