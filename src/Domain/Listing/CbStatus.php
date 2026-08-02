<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

/**
 * Herkunft des Tieres: Nachzucht, Wildfang oder unbekannt.
 */
enum CbStatus: string
{
    case Nachzucht = 'nz';
    case Wildfang = 'wf';
    case Unbekannt = 'unbekannt';

    public function label(): string
    {
        return match ($this) {
            self::Nachzucht => 'Nachzucht',
            self::Wildfang => 'Wildfang',
            self::Unbekannt => 'unbekannt',
        };
    }
}
