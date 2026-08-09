<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

/**
 * Kategorie oder Schlagwort.
 *
 * Der Unterschied ist nicht die Form, sondern was daran haengt: Eine Kategorie
 * bekommt ein Archiv unter /news/kategorie/{slug}/ und steht damit im
 * Suchindex einer Suchmaschine. Ein Schlagwort ordnet nur — sonst entstuenden
 * fuer jedes Wort, das jemand einmal vergeben hat, duenne Archivseiten.
 */
enum Taxonomy: string
{
    case Kategorie = 'kategorie';
    case Schlagwort = 'schlagwort';

    public function label(): string
    {
        return match ($this) {
            self::Kategorie => 'Kategorie',
            self::Schlagwort => 'Schlagwort',
        };
    }

    public function hasArchive(): bool
    {
        return $this === self::Kategorie;
    }
}
