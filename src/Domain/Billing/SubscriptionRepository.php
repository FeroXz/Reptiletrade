<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

use DateTimeImmutable;

interface SubscriptionRepository
{
    public function findById(int $id): ?Subscription;

    /**
     * Das laufende oder auf Zahlung wartende Abo eines Kontos.
     */
    public function activeForUser(int $userId): ?Subscription;

    public function findByProviderReference(string $provider, string $reference): ?Subscription;

    public function save(Subscription $subscription): int;

    public function updateStatus(int $id, SubscriptionStatus $status, DateTimeImmutable $at): void;

    public function markCancelAtPeriodEnd(int $id, DateTimeImmutable $at): void;

    public function extendPeriod(int $id, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $at): void;

    /**
     * Abgelaufene Abos — Arbeitsvorrat des Aufraeumjobs.
     *
     * @return list<Subscription>
     */
    public function expiredBefore(DateTimeImmutable $moment, int $limit = 100): array;
}
