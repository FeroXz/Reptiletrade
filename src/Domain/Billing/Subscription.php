<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

use DateTimeImmutable;

final readonly class Subscription
{
    public function __construct(
        public ?int $id,
        public int $userId,
        public string $planKey,
        public SubscriptionStatus $status,
        public DateTimeImmutable $currentPeriodStart,
        public DateTimeImmutable $currentPeriodEnd,
        public bool $cancelAtPeriodEnd = false,
        public string $provider = 'keiner',
        public ?string $providerReference = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    /**
     * Laeuft das Abo zu diesem Zeitpunkt? Der Status allein reicht nicht — ein
     * "aktiv" mit abgelaufener Periode ist ein Abo, das noch niemand
     * aufgeraeumt hat.
     */
    public function isActiveAt(DateTimeImmutable $moment): bool
    {
        return $this->status->grantsAccess() && $this->currentPeriodEnd > $moment;
    }

    public function daysLeft(DateTimeImmutable $moment): int
    {
        if ($this->currentPeriodEnd <= $moment) {
            return 0;
        }

        return (int) $moment->diff($this->currentPeriodEnd)->days;
    }

    public function renewsAutomatically(): bool
    {
        return $this->status === SubscriptionStatus::Aktiv && !$this->cancelAtPeriodEnd;
    }
}
