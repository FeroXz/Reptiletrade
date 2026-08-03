<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

use Reptilienmarkt\Domain\Geo\BoundingBox;
use Reptilienmarkt\Domain\Geo\Coordinates;
use Reptilienmarkt\Domain\Geo\Country;

/**
 * Mittelpunkt und Umkreis der Standortsuche.
 */
final readonly class RadiusFilter
{
    public function __construct(
        public Coordinates $center,
        public SearchRadius $radius,
        public ?string $postalCode = null,
        public ?Country $country = null,
        public ?string $placeName = null,
    ) {}

    /**
     * Das Rechteck fuer den indexgestuetzten Vorfilter. Die exakte Distanz
     * wird erst auf dem Ergebnis dieses Vorfilters berechnet.
     */
    public function boundingBox(): BoundingBox
    {
        return $this->center->boundingBox($this->radius->kilometers());
    }

    public function kilometers(): float
    {
        return $this->radius->kilometers();
    }

    public function label(): string
    {
        $location = $this->postalCode ?? '';
        if ($this->placeName !== null) {
            $location = trim($location . ' ' . $this->placeName);
        }

        return \sprintf('%s km um %s', (string) $this->radius->value, $location === '' ? 'den Standort' : $location);
    }
}
