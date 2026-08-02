<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support;

use DateTimeImmutable;

/**
 * Zeitquelle. Alles, was gegen "jetzt" prueft — Abgabealter, Ablauf von
 * Rechtstexten, Token-Gueltigkeit — geht hierueber, damit es testbar bleibt.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
