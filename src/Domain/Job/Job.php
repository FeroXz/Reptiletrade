<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Job;

use DateTimeImmutable;

final readonly class Job
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public ?int $id,
        public string $type,
        public array $payload = [],
        public string $queue = 'default',
        public JobStatus $status = JobStatus::Wartend,
        public int $attempts = 0,
        public int $maxAttempts = 3,
        public ?DateTimeImmutable $availableAt = null,
        public ?DateTimeImmutable $reservedAt = null,
        public ?string $reservedBy = null,
        public ?DateTimeImmutable $completedAt = null,
        public ?string $lastError = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    public function isExhausted(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->payload[$key] ?? null;

        return \is_int($value) ? $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->payload[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }
}
