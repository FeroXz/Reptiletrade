<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

use DateTimeImmutable;

interface PaymentRepository
{
    public function findById(int $id): ?Payment;

    public function findByProviderReference(string $provider, string $reference): ?Payment;

    public function save(Payment $payment): int;

    public function markPaid(int $id, DateTimeImmutable $at): void;

    public function updateStatus(int $id, PaymentStatus $status): void;

    /**
     * Belege eines Kontos, neueste zuerst.
     *
     * @return list<Payment>
     */
    public function forUser(int $userId, int $limit = 50): array;
}
