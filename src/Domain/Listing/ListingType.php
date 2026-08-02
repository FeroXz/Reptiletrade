<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

enum ListingType: string
{
    case Verkauf = 'verkauf';
    case Tausch = 'tausch';
    case Abgabe = 'abgabe';
    case Gesuch = 'gesuch';
    case NachzuchtVorbestellung = 'nachzucht_vorbestellung';

    public function label(): string
    {
        return match ($this) {
            self::Verkauf => 'Verkauf',
            self::Tausch => 'Tausch',
            self::Abgabe => 'Abgabe',
            self::Gesuch => 'Gesuch',
            self::NachzuchtVorbestellung => 'Nachzucht-Vorbestellung',
        };
    }

    public function requiresPrice(): bool
    {
        return $this === self::Verkauf || $this === self::NachzuchtVorbestellung;
    }

    /**
     * Ein Gesuch beschreibt kein konkretes Tier — Tierschutz- und Nachweisregeln
     * greifen dort nicht.
     */
    public function describesAnimalOnOffer(): bool
    {
        return $this !== self::Gesuch;
    }
}
