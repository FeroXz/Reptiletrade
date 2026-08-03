<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

use DateTimeImmutable;

final readonly class Boost
{
    public function __construct(
        public ?int $id,
        public int $listingId,
        public string $boostKey,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public ?int $paymentId = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    public function isActiveAt(DateTimeImmutable $moment): bool
    {
        return $this->startsAt <= $moment && $this->endsAt > $moment;
    }

    public function daysLeft(DateTimeImmutable $moment): int
    {
        return $this->endsAt <= $moment ? 0 : (int) $moment->diff($this->endsAt)->days;
    }
}
