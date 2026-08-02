<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

enum ListingStatus: string
{
    case Entwurf = 'entwurf';
    case Pruefung = 'pruefung';
    case Aktiv = 'aktiv';
    case Reserviert = 'reserviert';
    case Verkauft = 'verkauft';
    case Abgelaufen = 'abgelaufen';
    case Gesperrt = 'gesperrt';

    public function label(): string
    {
        return match ($this) {
            self::Entwurf => 'Entwurf',
            self::Pruefung => 'In Prüfung',
            self::Aktiv => 'Aktiv',
            self::Reserviert => 'Reserviert',
            self::Verkauft => 'Verkauft',
            self::Abgelaufen => 'Abgelaufen',
            self::Gesperrt => 'Gesperrt',
        };
    }

    /**
     * Oeffentlich sichtbar in Suche und Listen.
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Aktiv || $this === self::Reserviert;
    }
}
