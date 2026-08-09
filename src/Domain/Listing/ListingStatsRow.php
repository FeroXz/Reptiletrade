<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

/**
 * Eine Zeile der Anzeigenstatistik.
 */
final readonly class ListingStatsRow
{
    /**
     * $favorites steht bewusst nur hier und nirgends auf einer oeffentlichen
     * Seite: Eine sichtbare Merkzahl laedt dazu ein, sie hochzuschrauben, und
     * sagt dem Betrachter nichts, was ihm bei seiner Entscheidung hilft. Dem
     * Anbieter dagegen sagt sie, wie oft seine Anzeige jemanden interessiert
     * hat, der noch nicht geschrieben hat.
     */
    public function __construct(
        public int $id,
        public string $title,
        public ListingStatus $status,
        public int $views,
        public int $enquiries,
        public int $favorites = 0,
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
