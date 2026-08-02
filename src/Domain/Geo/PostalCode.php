<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Geo;

/**
 * Postleitzahl mit Zentroid. Datenquelle und Genauigkeit stehen in $source,
 * siehe data/README.md.
 */
final readonly class PostalCode
{
    public function __construct(
        public Country $country,
        public string $postalCode,
        public string $placeName,
        public ?string $admin1,
        public Coordinates $coordinates,
        public PostalCodeSource $source = PostalCodeSource::Geonames,
    ) {}

    public function label(): string
    {
        return $this->postalCode . ' ' . $this->placeName;
    }
}
