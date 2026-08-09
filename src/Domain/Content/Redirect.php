<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use DateTimeImmutable;

final readonly class Redirect
{
    public function __construct(
        public ?int $id,
        public string $fromPath,
        public string $toPath,
        public int $code = 301,
        public int $hits = 0,
        public ?DateTimeImmutable $lastUsedAt = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?int $createdBy = null,
        public bool $isAuto = false,
    ) {}

    public function isPermanent(): bool
    {
        return $this->code === 301;
    }
}
