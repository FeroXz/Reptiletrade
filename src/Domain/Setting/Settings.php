<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Setting;

/**
 * Betriebsschalter aus der Tabelle settings. Bewusst schmal gehalten:
 * getippte Lesezugriffe und ein Schreibzugriff.
 */
interface Settings
{
    public function has(string $key): bool;

    public function bool(string $key, bool $default = false): bool;

    public function int(string $key, int $default = 0): int;

    public function string(string $key, string $default = ''): string;

    /**
     * @return array<array-key, mixed>
     */
    public function json(string $key): array;

    /**
     * @param bool|int|string|array<array-key, mixed> $value
     */
    public function set(string $key, bool|int|string|array $value, ?string $description = null): void;
}
