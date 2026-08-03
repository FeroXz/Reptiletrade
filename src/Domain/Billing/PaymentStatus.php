<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

enum PaymentStatus: string
{
    case Offen = 'offen';
    case Bezahlt = 'bezahlt';
    case Fehlgeschlagen = 'fehlgeschlagen';
    case Erstattet = 'erstattet';

    public function label(): string
    {
        return match ($this) {
            self::Offen => 'Offen',
            self::Bezahlt => 'Bezahlt',
            self::Fehlgeschlagen => 'Fehlgeschlagen',
            self::Erstattet => 'Erstattet',
        };
    }

    public function isSettled(): bool
    {
        return $this === self::Bezahlt;
    }
}
