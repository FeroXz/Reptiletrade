<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

use DateTimeImmutable;

/**
 * Ein buchbarer Boost: Top-Platzierung fuer eine feste Zahl von Tagen.
 */
final readonly class BoostOption
{
    public function __construct(
        public string $key,
        public string $name,
        public int $days,
        public Money $price,
    ) {}

    public function endsAt(DateTimeImmutable $start): DateTimeImmutable
    {
        return $start->modify(\sprintf('+%d days', $this->days));
    }

    /**
     * Preis je Tag — macht die Staffelung vergleichbar.
     */
    public function pricePerDay(): Money
    {
        return new Money((int) round($this->price->cents / max(1, $this->days)), $this->price->currency);
    }
}
