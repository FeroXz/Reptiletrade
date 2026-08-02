<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support;

use RuntimeException;

/**
 * Minimaler .env-Loader. Bewusst ohne Fremdpaket: Wir brauchen nur KEY=VALUE,
 * Kommentare und optionale Anfuehrungszeichen.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    private static bool $loaded = false;

    public static function load(string $file, bool $required = false): void
    {
        if (!is_file($file)) {
            if ($required) {
                throw new RuntimeException(\sprintf('.env-Datei nicht gefunden: %s', $file));
            }
            self::$loaded = true;

            return;
        }

        $lines = file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException(\sprintf('.env-Datei nicht lesbar: %s', $file));
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (\count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if (\strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[\strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            self::$values[$key] = $value;
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (\array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        $fromEnv = getenv($key);

        return $fromEnv === false ? $default : $fromEnv;
    }

    public static function string(string $key, string $default = ''): string
    {
        return self::get($key, $default) ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return \in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /**
     * Nur fuer Tests: setzt den geladenen Zustand zurueck.
     */
    public static function reset(): void
    {
        self::$values = [];
        self::$loaded = false;
    }
}
