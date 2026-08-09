<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Search;

use Reptilienmarkt\Domain\Content\ContentBlockRepository;
use Reptilienmarkt\Domain\Content\ContentEntry;
use Reptilienmarkt\Domain\Content\ContentEntryRepository;
use Reptilienmarkt\Domain\Content\ContentSearchIndex;
use Reptilienmarkt\Domain\Content\ContentText;

/**
 * Stellt die Indexdokumente der Inhalte zusammen.
 *
 * Der Rumpftext entsteht aus den Bloecken und wird dabei aus Markdown zu
 * Reintext gemacht — an dieser einen Stelle. Ein zweiter Weg dafuer waere eine
 * zweite Wahrheit ueber denselben Text.
 *
 * Indexiert wird nur Veroeffentlichtes: Ein Entwurf, der ueber die Suche
 * auffindbar waere, ist kein Entwurf mehr.
 */
final readonly class ContentIndexer
{
    public function __construct(
        private ContentEntryRepository $entries,
        private ContentBlockRepository $blocks,
        private ContentText $text,
        private ContentSearchIndex $index,
    ) {}

    public function index(ContentEntry $entry): void
    {
        $id = $entry->id ?? 0;

        if (!$entry->isPublic()) {
            $this->index->remove($id);

            return;
        }

        $this->index->index(
            $id,
            $entry->title,
            $entry->excerpt,
            $this->text->plainText($this->blocks->forEntry($id)),
        );
    }

    public function remove(int $entryId): void
    {
        $this->index->remove($entryId);
    }

    /**
     * @return int Anzahl indizierter Eintraege
     */
    public function rebuildAll(): int
    {
        $indexed = 0;

        foreach ($this->entries->allPublished() as $entry) {
            $this->index($entry);
            ++$indexed;
        }

        return $indexed;
    }
}
