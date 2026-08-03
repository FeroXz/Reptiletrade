<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

enum BillingInterval: string
{
    case Keiner = 'keiner';
    case Monatlich = 'monatlich';
    case Jaehrlich = 'jaehrlich';

    public function label(): string
    {
        return match ($this) {
            self::Keiner => 'ohne Laufzeit',
            self::Monatlich => 'pro Monat',
            self::Jaehrlich => 'pro Jahr',
        };
    }

    /**
     * Der Ausdruck fuer DateTimeImmutable::modify().
     */
    public function step(): ?string
    {
        return match ($this) {
            self::Keiner => null,
            self::Monatlich => '+1 month',
            self::Jaehrlich => '+1 year',
        };
    }
}
