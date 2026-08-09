<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Listing\Favorite;
use Reptilienmarkt\Domain\Listing\FavoriteRepository;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoFavoriteRepository implements FavoriteRepository
{
    public function __construct(private Database $database) {}

    public function add(int $userId, int $listingId, DateTimeImmutable $at): bool
    {
        // Die Eindeutigkeit steht im Primaerschluessel; ein zweiter Klick soll
        // deshalb nicht scheitern, sondern nichts tun. "DO NOTHING" statt
        // "DO UPDATE": Der Zeitpunkt des ersten Merkens bleibt der richtige.
        return $this->database->execute(
            'INSERT INTO listing_favorites (user_id, listing_id, created_at)
             VALUES (:user, :listing, :now)
             ON CONFLICT (user_id, listing_id) DO NOTHING',
            ['user' => $userId, 'listing' => $listingId, 'now' => Timestamp::utc($at)],
        ) > 0;
    }

    public function remove(int $userId, int $listingId): bool
    {
        return $this->database->execute(
            'DELETE FROM listing_favorites WHERE user_id = :user AND listing_id = :listing',
            ['user' => $userId, 'listing' => $listingId],
        ) > 0;
    }

    public function has(int $userId, int $listingId): bool
    {
        $value = $this->database->scalar(
            'SELECT 1 FROM listing_favorites WHERE user_id = :user AND listing_id = :listing',
            ['user' => $userId, 'listing' => $listingId],
        );

        return $value !== null;
    }

    public function forUser(int $userId, int $limit = 200): array
    {
        $rows = $this->database->select(
            <<<'SQL'
                SELECT f.listing_id, f.created_at, l.title, l.status, l.price_cents, l.currency,
                       s.common_name_de,
                       (SELECT m.path FROM listing_media m
                         WHERE m.listing_id = l.id AND m.media_type = 'bild' AND m.is_primary = 1
                         LIMIT 1) AS bild
                  FROM listing_favorites f
                  JOIN listings l ON l.id = f.listing_id
                  LEFT JOIN species s ON s.id = l.species_id
                 WHERE f.user_id = :user
                 ORDER BY f.created_at DESC, f.listing_id DESC
                 LIMIT :limit
                SQL,
            ['user' => $userId, 'limit' => $limit],
        );

        return array_map($this->map(...), $rows);
    }

    public function countForUser(int $userId): int
    {
        $value = $this->database->scalar(
            'SELECT COUNT(*) FROM listing_favorites WHERE user_id = :user',
            ['user' => $userId],
        );

        return (int) (is_numeric($value) ? $value : 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Favorite
    {
        $bild = $row['bild'] ?? null;
        $art = $row['common_name_de'] ?? null;

        return new Favorite(
            (int) $row['listing_id'],
            (string) $row['title'],
            ListingStatus::from((string) $row['status']),
            $row['price_cents'] === null ? null : (int) $row['price_cents'],
            (string) ($row['currency'] ?? 'EUR'),
            \is_string($bild) ? $bild : null,
            \is_string($art) ? $art : null,
            Timestamp::parse(\is_string($row['created_at']) ? $row['created_at'] : null) ?? new DateTimeImmutable('@0'),
        );
    }
}
