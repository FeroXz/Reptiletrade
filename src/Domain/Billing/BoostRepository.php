<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

use DateTimeImmutable;

interface BoostRepository
{
    public function findById(int $id): ?Boost;

    public function activeForListing(int $listingId, DateTimeImmutable $moment): ?Boost;

    /**
     * @return list<Boost>
     */
    public function forListing(int $listingId): array;

    public function save(Boost $boost): int;

    /**
     * Boosts, deren Ende erreicht ist und deren Anzeige noch als
     * hervorgehoben markiert ist — Arbeitsvorrat des Ablaufjobs.
     *
     * @return list<Boost>
     */
    public function dueForExpiry(DateTimeImmutable $moment, int $limit = 200): array;
}
