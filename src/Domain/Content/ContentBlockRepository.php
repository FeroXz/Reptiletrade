<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

interface ContentBlockRepository
{
    /**
     * Die Bloecke eines Eintrags in Reihenfolge.
     *
     * @return list<ContentBlock>
     */
    public function forEntry(int $entryId): array;

    /**
     * Ersetzt die Blockliste vollstaendig.
     *
     * Ein Ersetzen statt einzelner Aenderungen: Der Editor schickt die ganze
     * Liste, und die Positionen werden dabei lueckenlos von 0 an neu vergeben.
     * Wer stattdessen einzelne Bloecke fortschriebe, muesste Luecken,
     * Doppelbelegungen und verwaiste Zeilen getrennt behandeln.
     *
     * @param list<ContentBlock> $blocks
     */
    public function replaceAll(int $entryId, array $blocks): void;

    public function deleteForEntry(int $entryId): void;
}
