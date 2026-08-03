<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

enum PaymentPurpose: string
{
    case Abo = 'abo';
    case Boost = 'boost';

    public function label(): string
    {
        return match ($this) {
            self::Abo => 'Mitgliedschaft',
            self::Boost => 'Top-Platzierung',
        };
    }
}
