<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Support;

use DateTimeImmutable;
use Reptilienmarkt\Support\Clock;

/**
 * Feste Zeit fuer Tests, die gegen "jetzt" pruefen.
 */
final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function travelTo(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
