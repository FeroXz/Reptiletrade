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

    /**
     * Die echte Prozessumgebung geht der .env-Datei vor.
     *
     * Andersherum waere die Datei nicht zu uebergehen: Ein Aufruf wie
     * `DB_DATABASE=/tmp/probe.sqlite php bin/migrate.php up` liefe still gegen
     * die Datenbank aus der .env, und wer eine Sicherung einspielt oder einen
     * Befehl einmalig gegen eine andere Ablage fahren will, merkt davon nichts,
     * bis der Schaden angerichtet ist. Dieselbe Reihenfolge gilt ueberall sonst
     * (Docker, systemd, CI): Die Datei ist der Satz Voreinstellungen, die
     * Umgebung ist die Ausnahme fuer diesen einen Aufruf.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $fromEnv = getenv($key);
        if ($fromEnv !== false) {
            return $fromEnv;
        }

        if (\array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        return $default;
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
