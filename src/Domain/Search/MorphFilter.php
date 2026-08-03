<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

use Reptilienmarkt\Domain\Listing\Zygosity;

/**
 * Ein ausgewaehltes Merkmal samt zugelassener Auspraegungen.
 *
 * Mehrere MorphFilter werden UND-verknuepft: Wer "Hypo" und "Translucent"
 * waehlt, sucht Tiere, die beides tragen.
 */
final readonly class MorphFilter
{
    /**
     * @param list<Zygosity> $zygosities leere Liste = jede Auspraegung
     */
    public function __construct(
        public int $morphId,
        public array $zygosities = [],
    ) {}

    /**
     * @return list<string>
     */
    public function zygosityValues(): array
    {
        return array_map(static fn(Zygosity $zygosity): string => $zygosity->value, $this->zygosities);
    }

    public function matchesAnyZygosity(): bool
    {
        return $this->zygosities === [];
    }
}
