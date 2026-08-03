<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Billing\Boost;
use Reptilienmarkt\Domain\Billing\BoostRepository;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoBoostRepository implements BoostRepository
{
    private const string COLUMNS = 'id, listing_id, boost_key, payment_id, starts_at, ends_at, created_at';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?Boost
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM listing_boosts WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->map($row);
    }

    public function activeForListing(int $listingId, DateTimeImmutable $moment): ?Boost
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM listing_boosts
              WHERE listing_id = :listing AND starts_at <= :moment AND ends_at > :moment
              ORDER BY ends_at DESC LIMIT 1',
            ['listing' => $listingId, 'moment' => Timestamp::utc($moment)],
        );

        return $row === null ? null : $this->map($row);
    }

    public function forListing(int $listingId): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM listing_boosts WHERE listing_id = :listing ORDER BY starts_at DESC',
            ['listing' => $listingId],
        );

        return array_map($this->map(...), $rows);
    }

    public function save(Boost $boost): int
    {
        $this->database->execute(
            'INSERT INTO listing_boosts (listing_id, boost_key, payment_id, starts_at, ends_at, created_at)
             VALUES (:listing, :key, :payment, :starts, :ends, :now)',
            [
                'listing' => $boost->listingId,
                'key' => $boost->boostKey,
                'payment' => $boost->paymentId,
                'starts' => Timestamp::utc($boost->startsAt),
                'ends' => Timestamp::utc($boost->endsAt),
                'now' => Timestamp::utcOrNull($boost->createdAt) ?? Timestamp::now(),
            ],
        );

        return $this->database->lastInsertId();
    }

    public function dueForExpiry(DateTimeImmutable $moment, int $limit = 200): array
    {
        // Nur Anzeigen, die noch hervorgehoben sind: Alles andere ist bereits
        // abgeraeumt, und der Job soll nicht bei jedem Lauf dieselbe Menge
        // durchgehen.
        $rows = $this->database->select(
            'SELECT b.id, b.listing_id, b.boost_key, b.payment_id, b.starts_at, b.ends_at, b.created_at
               FROM listing_boosts b
               JOIN listings l ON l.id = b.listing_id
              WHERE b.ends_at <= :moment AND l.is_featured = 1
              ORDER BY b.ends_at ASC LIMIT :limit',
            ['moment' => Timestamp::utc($moment), 'limit' => $limit],
        );

        return array_map($this->map(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Boost
    {
        return new Boost(
            (int) $row['id'],
            (int) $row['listing_id'],
            (string) $row['boost_key'],
            Timestamp::parse((string) $row['starts_at']) ?? new DateTimeImmutable(),
            Timestamp::parse((string) $row['ends_at']) ?? new DateTimeImmutable(),
            $row['payment_id'] === null ? null : (int) $row['payment_id'],
            Timestamp::parse(\is_string($row['created_at']) ? $row['created_at'] : null),
        );
    }
}
