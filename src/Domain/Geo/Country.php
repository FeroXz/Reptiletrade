<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Geo;

enum Country: string
{
    case De = 'DE';
    case At = 'AT';
    case Ch = 'CH';

    public function label(): string
    {
        return match ($this) {
            self::De => 'Deutschland',
            self::At => 'Österreich',
            self::Ch => 'Schweiz',
        };
    }

    public function currency(): string
    {
        return $this === self::Ch ? 'CHF' : 'EUR';
    }

    /**
     * Laenge der Postleitzahl: DE fuenfstellig, AT und CH vierstellig.
     */
    public function postalCodeLength(): int
    {
        return $this === self::De ? 5 : 4;
    }
}
