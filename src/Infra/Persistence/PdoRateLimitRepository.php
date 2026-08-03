<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Trust\RateLimitRepository;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoRateLimitRepository implements RateLimitRepository
{
    public function __construct(private Database $database) {}

    public function countSince(string $bucket, DateTimeImmutable $since): int
    {
        $value = $this->database->scalar(
            'SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = :bucket AND occurred_at >= :since',
            ['bucket' => $bucket, 'since' => Timestamp::utc($since)],
        );

        return (int) (is_numeric($value) ? $value : 0);
    }

    public function record(string $bucket, DateTimeImmutable $at): void
    {
        $this->database->execute(
            'INSERT INTO rate_limit_hits (bucket, occurred_at) VALUES (:bucket, :at)',
            ['bucket' => $bucket, 'at' => Timestamp::utc($at)],
        );
    }

    public function oldestSince(string $bucket, DateTimeImmutable $since): ?DateTimeImmutable
    {
        $value = $this->database->scalar(
            'SELECT MIN(occurred_at) FROM rate_limit_hits WHERE bucket = :bucket AND occurred_at >= :since',
            ['bucket' => $bucket, 'since' => Timestamp::utc($since)],
        );

        return \is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    public function purgeBefore(DateTimeImmutable $before): int
    {
        return $this->database->execute(
            'DELETE FROM rate_limit_hits WHERE occurred_at < :before',
            ['before' => Timestamp::utc($before)],
        );
    }
}
