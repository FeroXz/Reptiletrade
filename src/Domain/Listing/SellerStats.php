<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

/**
 * Aufrufe und Anfragen eines Anbieters.
 *
 * Zwei Zahlen tragen das Ganze: Wie oft wurde hingeschaut, und wie oft hat
 * jemand geschrieben. Das Verhaeltnis der beiden sagt mehr als jede von beiden
 * allein — viele Aufrufe ohne Anfragen heissen meist Preis oder Bilder.
 */
final readonly class SellerStats
{
    /**
     * @param array<string, int>    $viewsPerDay     Tag (Y-m-d) => Aufrufe
     * @param array<string, int>    $enquiriesPerDay Tag (Y-m-d) => Anfragen
     * @param list<ListingStatsRow> $listings
     */
    public function __construct(
        public int $views,
        public int $enquiries,
        public int $activeListings,
        public array $viewsPerDay,
        public array $enquiriesPerDay,
        public array $listings,
        public int $days,
    ) {}

    /**
     * Anfragen je hundert Aufrufe. Ohne Aufrufe gibt es nichts zu teilen.
     */
    public function enquiryRate(): ?float
    {
        return $this->views === 0 ? null : round($this->enquiries * 100 / $this->views, 1);
    }

    public function peakPerDay(): int
    {
        $werte = array_merge(array_values($this->viewsPerDay), [1]);

        return max($werte);
    }

    public function hasData(): bool
    {
        return $this->views > 0 || $this->enquiries > 0;
    }
}
