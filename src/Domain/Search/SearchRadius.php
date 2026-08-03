<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

/**
 * Die anwaehlbaren Umkreise. Bewusst eine feste Liste: Beliebige Radien wuerden
 * den Zwischenspeicher der Suchergebnisse zerfasern.
 */
enum SearchRadius: int
{
    case Km10 = 10;
    case Km25 = 25;
    case Km50 = 50;
    case Km100 = 100;
    case Km200 = 200;

    public function label(): string
    {
        return $this->value . ' km';
    }

    public function kilometers(): float
    {
        return (float) $this->value;
    }
}
