<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

/**
 * Was von einer Anzeige in den Volltextindex wandert.
 */
final readonly class IndexDocument
{
    /**
     * @param list<string> $morphTerms Merkmalsnamen samt Aliases
     * @param list<string> $speciesTerms wissenschaftlicher und deutscher Name
     */
    public function __construct(
        public int $listingId,
        public string $title,
        public string $description,
        public array $morphTerms = [],
        public array $speciesTerms = [],
    ) {}

    public function morphs(): string
    {
        return implode(' ', $this->morphTerms);
    }

    public function species(): string
    {
        return implode(' ', $this->speciesTerms);
    }
}
