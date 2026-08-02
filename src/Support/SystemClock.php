<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support;

use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemClock implements Clock
{
    public function __construct(private string $timezone = 'UTC') {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone($this->timezone));
    }
}
