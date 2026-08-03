<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Review;

interface ReviewRepository
{
    public function findById(int $id): ?Review;

    public function findByListingAndAuthor(int $listingId, int $fromUserId): ?Review;

    /**
     * Bewertungen ueber einen Nutzer, neueste zuerst.
     *
     * @return list<Review>
     */
    public function forUser(int $userId, int $limit = 20, int $offset = 0): array;

    public function save(Review $review): int;

    /**
     * @return array{anzahl: int, schnitt: ?float, verteilung: array<int, int>}
     */
    public function summaryFor(int $userId): array;

    public function delete(int $id): void;
}
