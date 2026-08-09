<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

use DateTimeImmutable;

interface FavoriteRepository
{
    /**
     * @return bool true, wenn der Eintrag neu war
     */
    public function add(int $userId, int $listingId, DateTimeImmutable $at): bool;

    /**
     * @return bool true, wenn ein Eintrag entfernt wurde
     */
    public function remove(int $userId, int $listingId): bool;

    public function has(int $userId, int $listingId): bool;

    /**
     * Die Merkliste eines Kontos, zuletzt Gemerktes zuerst.
     *
     * @return list<Favorite>
     */
    public function forUser(int $userId, int $limit = 200): array;

    public function countForUser(int $userId): int;
}
