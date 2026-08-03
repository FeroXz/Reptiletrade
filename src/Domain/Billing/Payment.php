<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

use DateTimeImmutable;

final readonly class Payment
{
    public function __construct(
        public ?int $id,
        public int $userId,
        public PaymentPurpose $purpose,
        public Money $amount,
        public PaymentStatus $status = PaymentStatus::Offen,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
        public float $taxRate = 0.0,
        public string $provider = 'keiner',
        public ?string $providerReference = null,
        public ?DateTimeImmutable $paidAt = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    public function taxPortion(): Money
    {
        return $this->amount->taxPortion($this->taxRate);
    }

    public function netAmount(): Money
    {
        return $this->amount->minus($this->taxPortion());
    }
}
