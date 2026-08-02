<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Geo;

/**
 * Achsenparalleles Rechteck fuer den indexgestuetzten Vorfilter der Umkreissuche.
 */
final readonly class BoundingBox
{
    public function __construct(
        public float $minLatitude,
        public float $maxLatitude,
        public float $minLongitude,
        public float $maxLongitude,
    ) {}

    public function contains(Coordinates $point): bool
    {
        return $point->latitude >= $this->minLatitude
            && $point->latitude <= $this->maxLatitude
            && $point->longitude >= $this->minLongitude
            && $point->longitude <= $this->maxLongitude;
    }
}
