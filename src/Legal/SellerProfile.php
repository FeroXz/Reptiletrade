<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

/**
 * Die Kennzahlen des Anbieters, die die Gewerblichkeitsregel braucht.
 */
final readonly class SellerProfile
{
    public function __construct(
        public int $activeListingCount = 0,
        public int $salesLastTwelveMonths = 0,
        public bool $isCommercial = false,
        public ?string $erlaubnis11Number = null,
        public bool $hasImprint = false,
    ) {}

    public function hasErlaubnis11Number(): bool
    {
        return $this->erlaubnis11Number !== null && trim($this->erlaubnis11Number) !== '';
    }
}
