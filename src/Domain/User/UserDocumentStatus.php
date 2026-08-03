<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

enum UserDocumentStatus: string
{
    case Offen = 'offen';
    case Geprueft = 'geprueft';
    case Abgelehnt = 'abgelehnt';

    public function label(): string
    {
        return match ($this) {
            self::Offen => 'In Prüfung',
            self::Geprueft => 'Geprüft',
            self::Abgelehnt => 'Abgelehnt',
        };
    }
}
