<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Moderation;

enum ReportTargetType: string
{
    case Listing = 'listing';
    case User = 'user';
    case Message = 'message';

    public function label(): string
    {
        return match ($this) {
            self::Listing => 'Anzeige',
            self::User => 'Profil',
            self::Message => 'Nachricht',
        };
    }
}
