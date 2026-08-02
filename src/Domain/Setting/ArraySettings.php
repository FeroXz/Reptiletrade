<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Setting;

/**
 * Einstellungen im Speicher — fuer Tests und fuer Kontexte ohne Datenbank.
 */
final class ArraySettings implements Settings
{
    /**
     * @param array<string, bool|int|string|array<array-key, mixed>> $values
     */
    public function __construct(private array $values = []) {}

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->values);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->values[$key] ?? null;

        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return $value === 1;
        }

        if (\is_string($value)) {
            return \in_array(strtolower($value), ['1', 'true', 'ja', 'yes', 'on'], true);
        }

        return $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->values[$key] ?? null;

        return \is_int($value) || (\is_string($value) && is_numeric($value)) ? (int) $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    public function json(string $key): array
    {
        $value = $this->values[$key] ?? null;

        return \is_array($value) ? $value : [];
    }

    public function set(string $key, bool|int|string|array $value, ?string $description = null): void
    {
        $this->values[$key] = $value;
    }
}
