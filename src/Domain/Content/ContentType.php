<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

/**
 * Seite oder Beitrag. Der Unterschied ist nicht die Form, sondern die Adresse:
 * Eine Seite haengt in einer Hierarchie und liegt unter ihrem eigenen Pfad, ein
 * Beitrag liegt unter /news/{jahr}/{slug}/ und traegt ein Datum, das zaehlt.
 */
enum ContentType: string
{
    case Seite = 'seite';
    case Beitrag = 'beitrag';

    public function label(): string
    {
        return match ($this) {
            self::Seite => 'Seite',
            self::Beitrag => 'Beitrag',
        };
    }

    /**
     * Nur Seiten haengen unter einer anderen Seite.
     */
    public function allowsParent(): bool
    {
        return $this === self::Seite;
    }
}
