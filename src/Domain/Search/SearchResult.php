<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

final readonly class SearchResult
{
    /**
     * @param list<ListingSummary> $listings
     */
    public function __construct(
        public array $listings,
        public int $total,
        public FacetCounts $facets,
        public SearchCriteria $criteria,
        public float $durationMs = 0.0,
    ) {}

    public function isEmpty(): bool
    {
        return $this->listings === [];
    }

    public function page(): int
    {
        return max(1, $this->criteria->page);
    }

    public function pageCount(): int
    {
        return (int) max(1, ceil($this->total / $this->criteria->limit()));
    }

    public function hasPreviousPage(): bool
    {
        return $this->page() > 1;
    }

    public function hasNextPage(): bool
    {
        return $this->page() < $this->pageCount();
    }

    public function firstResultNumber(): int
    {
        return $this->total === 0 ? 0 : $this->criteria->offset() + 1;
    }

    public function lastResultNumber(): int
    {
        return min($this->total, $this->criteria->offset() + \count($this->listings));
    }
}
