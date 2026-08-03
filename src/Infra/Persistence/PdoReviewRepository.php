<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Review\Review;
use Reptilienmarkt\Domain\Review\ReviewRepository;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoReviewRepository implements ReviewRepository
{
    private const string COLUMNS = 'id, listing_id, from_user_id, to_user_id, rating, comment, deal_confirmed_at, created_at';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?Review
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM reviews WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->map($row);
    }

    public function findByListingAndAuthor(int $listingId, int $fromUserId): ?Review
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM reviews WHERE listing_id = :listing AND from_user_id = :author',
            ['listing' => $listingId, 'author' => $fromUserId],
        );

        return $row === null ? null : $this->map($row);
    }

    public function forUser(int $userId, int $limit = 20, int $offset = 0): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM reviews WHERE to_user_id = :user
             ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            ['user' => $userId, 'limit' => $limit, 'offset' => $offset],
        );

        return array_map($this->map(...), $rows);
    }

    public function save(Review $review): int
    {
        $this->database->execute(
            'INSERT INTO reviews (listing_id, from_user_id, to_user_id, rating, comment, deal_confirmed_at, created_at)
             VALUES (:listing, :from_user, :to_user, :rating, :comment, :confirmed, :now)',
            [
                'listing' => $review->listingId,
                'from_user' => $review->fromUserId,
                'to_user' => $review->toUserId,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'confirmed' => Timestamp::utc($review->dealConfirmedAt),
                'now' => Timestamp::utcOrNull($review->createdAt) ?? Timestamp::now(),
            ],
        );

        return $this->database->lastInsertId();
    }

    public function summaryFor(int $userId): array
    {
        $rows = $this->database->select(
            'SELECT rating, COUNT(*) AS anzahl FROM reviews WHERE to_user_id = :user GROUP BY rating',
            ['user' => $userId],
        );

        $distribution = [];
        $total = 0;
        $sum = 0;

        foreach ($rows as $row) {
            $rating = (int) $row['rating'];
            $count = (int) $row['anzahl'];

            $distribution[$rating] = $count;
            $total += $count;
            $sum += $rating * $count;
        }

        return [
            'anzahl' => $total,
            'schnitt' => $total === 0 ? null : $sum / $total,
            'verteilung' => $distribution,
        ];
    }

    public function delete(int $id): void
    {
        $this->database->execute('DELETE FROM reviews WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Review
    {
        return new Review(
            (int) $row['id'],
            (int) $row['listing_id'],
            (int) $row['from_user_id'],
            (int) $row['to_user_id'],
            (int) $row['rating'],
            \is_string($row['comment']) ? $row['comment'] : null,
            Timestamp::parse((string) $row['deal_confirmed_at']) ?? new DateTimeImmutable(),
            Timestamp::parse(\is_string($row['created_at']) ? $row['created_at'] : null),
        );
    }
}
