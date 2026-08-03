<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Review;

use DateTimeImmutable;

final readonly class Review
{
    public const int MIN_RATING = 1;

    public const int MAX_RATING = 5;

    public const int MAX_COMMENT_LENGTH = 1500;

    public function __construct(
        public ?int $id,
        public int $listingId,
        public int $fromUserId,
        public int $toUserId,
        public int $rating,
        public ?string $comment,
        public DateTimeImmutable $dealConfirmedAt,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    public function hasComment(): bool
    {
        return $this->comment !== null && trim($this->comment) !== '';
    }
}
