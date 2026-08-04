<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

/**
 * Wer hat die Anzeige pausiert?
 *
 * Der Unterschied ist keine Buchhaltung, sondern die Regel selbst: Eine Pause
 * der Verwaltung hebt der Anbieter nicht selbst wieder auf. Sonst waere die
 * Massnahme einen Klick wert.
 */
enum PauseActor: string
{
    case Anbieter = 'anbieter';
    case Verwaltung = 'verwaltung';

    public function label(): string
    {
        return match ($this) {
            self::Anbieter => 'vom Anbieter pausiert',
            self::Verwaltung => 'von der Verwaltung pausiert',
        };
    }

    public function mayBeResumedByOwner(): bool
    {
        return $this === self::Anbieter;
    }
}
