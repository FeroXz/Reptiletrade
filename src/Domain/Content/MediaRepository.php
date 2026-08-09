<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

interface MediaRepository
{
    public function findById(int $id): ?Media;

    /**
     * Die Deduplizierung: Gibt es dieses Bild schon?
     */
    public function findByHash(string $sha256): ?Media;

    /**
     * @param list<int> $ids
     *
     * @return array<int, Media> nach ID
     */
    public function findMany(array $ids): array;

    public function save(Media $media): int;

    public function updateText(int $id, string $altText, string $caption): void;

    public function delete(int $id): void;

    /**
     * Neueste zuerst.
     *
     * @return list<Media>
     */
    public function latest(int $limit = 60, int $offset = 0, ?string $query = null): array;

    public function count(?string $query = null): int;
}
