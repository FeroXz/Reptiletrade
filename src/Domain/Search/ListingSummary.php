<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Listing\CbStatus;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\Sex;

/**
 * Eine Anzeige in der Trefferliste. Bewusst schmaler als die Listing-Entitaet:
 * Die Kachel braucht nur diese Felder, und die Suche soll keine Beschreibungen
 * von 50 000 Zeilen durch den Speicher schieben.
 */
final readonly class ListingSummary
{
    /**
     * @param list<string> $morphNames
     */
    public function __construct(
        public int $id,
        public string $title,
        public ListingType $type,
        public int $speciesId,
        public string $speciesCommonName,
        public string $speciesSlug,
        public ?string $speciesCommonSlug,
        public array $morphNames,
        public ?int $priceCents,
        public string $currency,
        public bool $negotiable,
        public Sex $sex,
        public CbStatus $cbStatus,
        public ?string $postalCode,
        public ?Country $country,
        public ?string $imagePath,
        public ?float $distanceKm,
        public bool $isFeatured,
        public ?DateTimeImmutable $bumpedAt,
        public ?int $imageWidth = null,
        public ?int $imageHeight = null,
        /**
         * Die vorhandenen Bildbreiten fuer srcset, sortiert und kommagetrennt.
         * Sie stehen in der Zeile, damit die Kachel sie nicht auf der Platte
         * nachsehen muss.
         */
        public ?string $imageVariantWidths = null,
    ) {}

    public function hasPrice(): bool
    {
        return $this->priceCents !== null;
    }

    public function formattedPrice(): string
    {
        if ($this->priceCents === null) {
            return $this->type === ListingType::Tausch ? 'Nur Tausch' : 'Preis auf Anfrage';
        }

        $amount = number_format($this->priceCents / 100, 2, ',', '.');
        $suffix = $this->negotiable ? ' VB' : '';

        return $amount . ' ' . $this->currency . $suffix;
    }

    public function formattedDistance(): ?string
    {
        if ($this->distanceKm === null) {
            return null;
        }

        return $this->distanceKm < 10.0
            ? number_format($this->distanceKm, 1, ',', '.') . ' km'
            : number_format($this->distanceKm, 0, ',', '.') . ' km';
    }

    public function morphLabel(): string
    {
        return implode(', ', $this->morphNames);
    }
}
