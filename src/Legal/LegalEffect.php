<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

/**
 * Wirkung eines Regeltreffers.
 */
enum LegalEffect: string
{
    case Blockiert = 'blockiert';
    case Pruefung = 'pruefung';
    case Pflichtfeld = 'pflichtfeld';
    case Hinweis = 'hinweis';

    public function label(): string
    {
        return match ($this) {
            self::Blockiert => 'Veröffentlichung blockiert',
            self::Pruefung => 'Manuelle Prüfung nötig',
            self::Pflichtfeld => 'Pflichtfeld',
            self::Hinweis => 'Hinweis',
        };
    }
}
