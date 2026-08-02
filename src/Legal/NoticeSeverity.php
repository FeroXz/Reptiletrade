<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

enum NoticeSeverity: string
{
    case Info = 'info';
    case Warnung = 'warnung';
    case Kritisch = 'kritisch';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Hinweis',
            self::Warnung => 'Warnung',
            self::Kritisch => 'Kritisch',
        };
    }
}
