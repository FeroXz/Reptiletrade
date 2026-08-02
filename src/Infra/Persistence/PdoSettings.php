<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use Reptilienmarkt\Domain\Setting\Settings;

final class PdoSettings implements Settings
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $database) {}

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->load());
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->load()[$key] ?? null;
        if ($value === null) {
            return $default;
        }

        return \in_array(strtolower($value), ['1', 'true', 'ja', 'yes', 'on'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->load()[$key] ?? null;

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        return $this->load()[$key] ?? $default;
    }

    public function json(string $key): array
    {
        $value = $this->load()[$key] ?? null;
        if ($value === null) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }

    public function set(string $key, bool|int|string|array $value, ?string $description = null): void
    {
        [$stored, $type] = match (true) {
            \is_bool($value) => [$value ? '1' : '0', 'bool'],
            \is_int($value) => [(string) $value, 'int'],
            \is_array($value) => [json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE), 'json'],
            default => [$value, 'string'],
        };

        $this->database->execute(
            'INSERT INTO settings (setting_key, value, value_type, description, updated_at)
             VALUES (:key, :value, :type, :description, :now)
             ON CONFLICT(setting_key) DO UPDATE SET
                value = excluded.value,
                value_type = excluded.value_type,
                description = COALESCE(excluded.description, settings.description),
                updated_at = excluded.updated_at',
            [
                'key' => $key,
                'value' => $stored,
                'type' => $type,
                'description' => $description,
                'now' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        );

        $this->cache = null;
    }

    /**
     * @return array<string, string>
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $values = [];
        foreach ($this->database->select('SELECT setting_key, value FROM settings') as $row) {
            $values[(string) $row['setting_key']] = (string) $row['value'];
        }

        return $this->cache = $values;
    }
}
