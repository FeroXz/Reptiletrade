<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Auth;

use DateTimeImmutable;

final readonly class TokenRecord
{
    public function __construct(
        public int $id,
        public int $userId,
        public TokenType $type,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $usedAt = null,
    ) {}

    public function isUsable(DateTimeImmutable $now): bool
    {
        return $this->usedAt === null && $this->expiresAt > $now;
    }
}
