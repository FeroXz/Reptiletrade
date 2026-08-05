<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

/**
 * Was ein Gelege dieser Art typischerweise hergibt.
 *
 * Aus Wahrscheinlichkeiten werden damit Stueckzahlen — die Form, in der
 * Zuechter rechnen: nicht "25 %", sondern "etwa vier von sechzehn".
 */
final readonly class ClutchProfile
{
    public function __construct(
        public int $size,
        public float $hatchRate,
    ) {}

    /**
     * Erwartete Schluepflinge, bevor die Genetik hineinspielt.
     */
    public function expectedHatchlings(): float
    {
        return $this->size * $this->hatchRate;
    }
}
