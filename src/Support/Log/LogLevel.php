<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support\Log;

enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    /**
     * Rang fuer den Schwellenvergleich.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Warning => 2,
            self::Error => 3,
        };
    }

    public function atLeast(self $threshold): bool
    {
        return $this->severity() >= $threshold->severity();
    }
}
