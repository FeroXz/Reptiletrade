<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

interface ListingMediaRepository
{
    /**
     * @return list<ListingMediaItem>
     */
    public function forListing(int $listingId): array;

    public function find(int $mediaId): ?ListingMediaItem;

    public function add(ListingMediaItem $item): int;

    public function delete(int $mediaId): void;

    public function countImages(int $listingId): int;

    public function hasVideo(int $listingId): bool;

    /**
     * Setzt genau ein Bild als Titelbild; das bisherige verliert die Markierung.
     */
    public function setPrimary(int $listingId, int $mediaId): void;
}
