<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Trust;

use DateTimeImmutable;

final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $remaining,
        public RateLimit $limit,
        public ?DateTimeImmutable $retryAt = null,
    ) {}

    /**
     * Sekunden bis zum naechsten erlaubten Versuch — fuer Retry-After.
     */
    public function retryAfterSeconds(DateTimeImmutable $now): int
    {
        if ($this->retryAt === null) {
            return 0;
        }

        return max(0, $this->retryAt->getTimestamp() - $now->getTimestamp());
    }

    public function message(): string
    {
        return \sprintf(
            'Zu viele Versuche. Bitte warte etwa %d Minuten.',
            $this->limit->windowMinutes(),
        );
    }
}
