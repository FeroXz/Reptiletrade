<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Site;

use DateTimeImmutable;

/**
 * Ein Text, den die Verwaltung anders gesetzt hat als ausgeliefert.
 */
final readonly class TextOverride
{
    public function __construct(
        public string $locale,
        public string $key,
        public string $value,
        public DateTimeImmutable $updatedAt,
        public ?int $updatedBy = null,
    ) {}
}
