<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

/**
 * Eine Zeile der Anzeigenstatistik.
 */
final readonly class ListingStatsRow
{
    public function __construct(
        public int $id,
        public string $title,
        public ListingStatus $status,
        public int $views,
        public int $enquiries,
    ) {}

    /**
     * Anfragen je hundert Aufrufe — die Zahl, an der sich eine Anzeige
     * verbessern laesst.
     */
    public function enquiryRate(): ?float
    {
        return $this->views === 0 ? null : round($this->enquiries * 100 / $this->views, 1);
    }
}
