<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

enum UserStatus: string
{
    case Aktiv = 'aktiv';
    case Gesperrt = 'gesperrt';
    case Geloescht = 'geloescht';

    public function label(): string
    {
        return match ($this) {
            self::Aktiv => 'aktiv',
            self::Gesperrt => 'gesperrt',
            self::Geloescht => 'gelöscht',
        };
    }
}
