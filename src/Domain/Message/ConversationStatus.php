<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Message;

enum ConversationStatus: string
{
    case Offen = 'offen';
    case Geschlossen = 'geschlossen';
    case Gemeldet = 'gemeldet';

    public function label(): string
    {
        return match ($this) {
            self::Offen => 'Offen',
            self::Geschlossen => 'Geschlossen',
            self::Gemeldet => 'Gemeldet',
        };
    }

    public function acceptsMessages(): bool
    {
        return $this === self::Offen;
    }
}
