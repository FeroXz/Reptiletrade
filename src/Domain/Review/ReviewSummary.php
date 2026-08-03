<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Review;

/**
 * Der Bewertungsschnitt eines Nutzers samt Verteilung.
 */
final readonly class ReviewSummary
{
    /**
     * @param array<int, int> $distribution Sterne => Anzahl
     */
    public function __construct(
        public int $count,
        public ?float $average,
        public array $distribution = [],
    ) {}

    public function hasReviews(): bool
    {
        return $this->count > 0;
    }

    /**
     * Auf eine Nachkommastelle — mehr Genauigkeit taeuscht bei zwoelf
     * Bewertungen eine Praezision vor, die es nicht gibt.
     */
    public function rounded(): ?float
    {
        return $this->average === null ? null : round($this->average, 1);
    }

    public function share(int $stars): float
    {
        if ($this->count === 0) {
            return 0.0;
        }

        return ($this->distribution[$stars] ?? 0) / $this->count * 100;
    }
}
