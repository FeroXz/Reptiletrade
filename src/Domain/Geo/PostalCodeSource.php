<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Geo;

/**
 * Herkunft eines PLZ-Zentroids. "interpoliert" bedeutet: aus numerisch benachbarten
 * Postleitzahlen abgeleitet, weil die Quelle keinen eigenen Punkt liefert.
 * Diese Werte lassen sich per bin/import_postal_codes.php --geonames ersetzen.
 */
enum PostalCodeSource: string
{
    case Geonames = 'geonames';
    case GeonamesPlace = 'geonames_place';
    case Interpolated = 'interpoliert';

    public function isExact(): bool
    {
        return $this !== self::Interpolated;
    }
}
