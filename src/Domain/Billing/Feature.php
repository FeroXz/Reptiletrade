<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

/**
 * Merkmale, die ein Tarif freischaltet. Als Enum statt als freie Zeichenkette,
 * damit ein Tippfehler in der Konfiguration beim Aufbau auffaellt und nicht
 * als stillschweigend fehlendes Merkmal.
 */
enum Feature: string
{
    case Profilseite = 'profilseite';
    case Statistiken = 'statistiken';
    case NachzuchtAnkuendigung = 'nachzucht_ankuendigung';

    public function label(): string
    {
        return match ($this) {
            self::Profilseite => 'Öffentliche Züchterseite',
            self::Statistiken => 'Statistiken zu Aufrufen und Anfragen',
            self::NachzuchtAnkuendigung => 'Ankündigungen kommender Nachzuchten',
        };
    }
}
