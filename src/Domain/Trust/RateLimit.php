<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Trust;

/**
 * Eine Obergrenze: so viele Versuche in so vielen Sekunden.
 */
final readonly class RateLimit
{
    public function __construct(
        public string $name,
        public int $limit,
        public int $windowSeconds,
    ) {}

    public function windowMinutes(): int
    {
        return max(1, (int) round($this->windowSeconds / 60));
    }
}
