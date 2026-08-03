<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

/**
 * Trefferzahlen je Facettenwert.
 *
 * Eine Facette wird gegen alle aktiven Filter AUSSER der eigenen Dimension
 * gezaehlt. Sonst faellt jeder nicht gewaehlte Wert auf null, sobald in dieser
 * Dimension etwas ausgewaehlt ist — und die Facette wird nutzlos.
 */
final readonly class FacetCounts
{
    public const string SPECIES = 'species';
    public const string TYPE = 'type';
    public const string SEX = 'sex';
    public const string CB_STATUS = 'cb_status';
    public const string COUNTRY = 'country';
    public const string HANDOVER = 'handover';
    public const string MORPH = 'morph';

    /**
     * @param array<string, array<string, int>> $counts Dimension -> Wert -> Anzahl
     * @param array<string, string>             $labels Zusatzbeschriftungen, z. B. Art-ID -> deutscher Name
     */
    public function __construct(
        private array $counts = [],
        private array $labels = [],
    ) {}

    /**
     * @return array<string, int>
     */
    public function forDimension(string $dimension): array
    {
        return $this->counts[$dimension] ?? [];
    }

    public function count(string $dimension, string $value): int
    {
        return $this->counts[$dimension][$value] ?? 0;
    }

    public function label(string $value, string $fallback = ''): string
    {
        return $this->labels[$value] ?? $fallback;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function all(): array
    {
        return $this->counts;
    }

    public function isEmpty(): bool
    {
        return $this->counts === [];
    }
}
