<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Species;

/**
 * Schutzstatus nach BNatSchG.
 */
enum BnatschgStatus: string
{
    case NichtGeschuetzt = 'nicht_geschuetzt';
    case Besonders = 'besonders';
    case Streng = 'streng';

    public function label(): string
    {
        return match ($this) {
            self::NichtGeschuetzt => 'nicht geschützt',
            self::Besonders => 'besonders geschützt',
            self::Streng => 'streng geschützt',
        };
    }
}
