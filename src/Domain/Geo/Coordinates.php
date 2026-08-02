<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Geo;

use InvalidArgumentException;

/**
 * WGS84-Koordinate.
 */
final readonly class Coordinates
{
    private const float EARTH_RADIUS_KM = 6371.0088;

    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
        if ($latitude < -90.0 || $latitude > 90.0) {
            throw new InvalidArgumentException(\sprintf('Breitengrad ausserhalb des gueltigen Bereichs: %F', $latitude));
        }

        if ($longitude < -180.0 || $longitude > 180.0) {
            throw new InvalidArgumentException(\sprintf('Laengengrad ausserhalb des gueltigen Bereichs: %F', $longitude));
        }
    }

    /**
     * Grosskreisdistanz in Kilometern (Haversine).
     */
    public function distanceKmTo(self $other): float
    {
        $latFrom = deg2rad($this->latitude);
        $latTo = deg2rad($other->latitude);
        $deltaLat = $latTo - $latFrom;
        $deltaLng = deg2rad($other->longitude - $this->longitude);

        $a = sin($deltaLat / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($deltaLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    /**
     * Umschliessendes Rechteck fuer den Vorfilter per Index. Die exakte
     * Haversine-Distanz wird erst auf dem Ergebnis dieses Vorfilters berechnet.
     */
    public function boundingBox(float $radiusKm): BoundingBox
    {
        $latDelta = rad2deg($radiusKm / self::EARTH_RADIUS_KM);
        $cosLat = cos(deg2rad($this->latitude));
        // Nahe den Polen laeuft der Laengengrad-Delta gegen unendlich; deckeln.
        $lngDelta = abs($cosLat) < 1.0e-9
            ? 180.0
            : rad2deg($radiusKm / (self::EARTH_RADIUS_KM * abs($cosLat)));

        return new BoundingBox(
            max(-90.0, $this->latitude - $latDelta),
            min(90.0, $this->latitude + $latDelta),
            max(-180.0, $this->longitude - $lngDelta),
            min(180.0, $this->longitude + $lngDelta),
        );
    }
}
