<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

use DateTimeImmutable;

/**
 * Ein Eintrag der Merkliste, fertig fuer die Anzeige.
 *
 * Traegt den Zustand der Anzeige mit: Eine pausierte oder abgelaufene Anzeige
 * bleibt in der Liste stehen und wird gekennzeichnet. Sie kommentarlos
 * verschwinden zu lassen sieht wie ein Fehler aus — der Nutzer weiss dann
 * nicht, ob er sich geirrt hat oder die Anwendung.
 */
final readonly class Favorite
{
    public function __construct(
        public int $listingId,
        public string $title,
        public ListingStatus $status,
        public ?int $priceCents,
        public string $currency,
        public ?string $imagePath,
        public ?string $speciesCommonName,
        public DateTimeImmutable $markedAt,
    ) {}

    /**
     * Laesst sich die Anzeige noch aufrufen? Steuert, ob der Eintrag verlinkt
     * oder nur genannt wird.
     */
    public function isReachable(): bool
    {
        return $this->status->isPubliclyVisible();
    }
}
