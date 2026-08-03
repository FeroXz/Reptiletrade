<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Job;

enum JobStatus: string
{
    case Wartend = 'wartend';
    case Laeuft = 'laeuft';
    case Erledigt = 'erledigt';
    case Fehlgeschlagen = 'fehlgeschlagen';

    public function label(): string
    {
        return match ($this) {
            self::Wartend => 'Wartend',
            self::Laeuft => 'Läuft',
            self::Erledigt => 'Erledigt',
            self::Fehlgeschlagen => 'Fehlgeschlagen',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Erledigt || $this === self::Fehlgeschlagen;
    }
}
