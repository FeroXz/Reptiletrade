<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

/**
 * Wie ernst ist ein Hinweis der Vererbungsrechnung?
 *
 * Die Abstufung entscheidet ueber die Darstellung: Ein Hinweis erklaert, eine
 * Warnung soll gelesen werden, ein Fehler bedeutet, dass die Verpaarung so
 * nicht stattfinden sollte.
 */
enum WarningSeverity: string
{
    case Hinweis = 'hinweis';
    case Warnung = 'warnung';
    case Fehler = 'fehler';

    public function label(): string
    {
        return match ($this) {
            self::Hinweis => 'Hinweis',
            self::Warnung => 'Warnung',
            self::Fehler => 'Kritisch',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Fehler => 3,
            self::Warnung => 2,
            self::Hinweis => 1,
        };
    }
}
