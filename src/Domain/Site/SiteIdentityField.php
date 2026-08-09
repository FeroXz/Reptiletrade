<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Site;

use DateTimeImmutable;

/**
 * Ein Feld des Impressums, wie es die Verwaltung sieht: ausgelieferter Stand
 * links, geltender Wert rechts.
 */
final readonly class SiteIdentityField
{
    public function __construct(
        public string $key,
        public string $group,
        public string $label,
        public string $hint,
        public string $delivered,
        public string $effective,
        public bool $overridden,
        public bool $required = false,
        public bool $multiline = false,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}

    public function section(): string
    {
        $punkt = strpos($this->key, '.');

        return $punkt === false ? $this->key : substr($this->key, 0, $punkt);
    }

    /**
     * Steht hier noch ein Platzhalter aus der Auslieferung?
     */
    public function isPlaceholder(): bool
    {
        return str_contains($this->effective, 'AUSFÜLLEN') || $this->effective === '00000';
    }
}
