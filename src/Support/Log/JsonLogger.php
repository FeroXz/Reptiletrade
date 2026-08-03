<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support\Log;

use DateTimeInterface;

/**
 * Schreibt eine JSON-Zeile je Ereignis.
 *
 * Eine Zeile je Ereignis, damit sich das Protokoll mit den ueblichen
 * Werkzeugen lesen laesst — grep, jq, oder eine Sammelstelle. Mehrzeilige
 * Eintraege sind beim Suchen wertlos, weil die zweite Zeile den Zusammenhang
 * verliert.
 *
 * Bekannte Geheimnisfelder werden vor dem Schreiben ersetzt. Ein Protokoll
 * wird kopiert, verschickt und selten so geschuetzt wie die Datenbank —
 * Passwoerter und Token gehoeren dort nicht hinein.
 */
final readonly class JsonLogger implements Logger
{
    /** @var list<string> */
    private const array REDACTED_KEYS = [
        'passwort', 'password', 'passwort_hash', 'password_hash', 'token', 'token_hash',
        'secret', 'geheimnis', 'totp_secret', 'csrf', 'authorization', 'cookie',
        'stripe_secret_key', 'webhook_secret',
    ];

    private const int MAX_VALUE_LENGTH = 2000;

    public function __construct(
        private string $file,
        private LogLevel $threshold = LogLevel::Info,
        private string $channel = 'app',
    ) {}

    public function debug(string $event, array $context = []): void
    {
        $this->write(LogLevel::Debug, $event, $context);
    }

    public function info(string $event, array $context = []): void
    {
        $this->write(LogLevel::Info, $event, $context);
    }

    public function warning(string $event, array $context = []): void
    {
        $this->write(LogLevel::Warning, $event, $context);
    }

    public function error(string $event, array $context = []): void
    {
        $this->write(LogLevel::Error, $event, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(LogLevel $level, string $event, array $context): void
    {
        if (!$level->atLeast($this->threshold)) {
            return;
        }

        $zeile = json_encode(
            [
                'zeitpunkt' => gmdate('c'),
                'stufe' => $level->value,
                'kanal' => $this->channel,
                'ereignis' => $event,
            ] + $this->sanitize($context),
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if ($zeile === false) {
            $zeile = json_encode(['zeitpunkt' => gmdate('c'), 'stufe' => 'error', 'ereignis' => 'log.encode_failed']);
        }

        $verzeichnis = \dirname($this->file);
        if (!is_dir($verzeichnis) && !mkdir($verzeichnis, 0o775, true) && !is_dir($verzeichnis)) {
            return;
        }

        // LOCK_EX, damit sich gleichzeitig schreibende Prozesse nicht in die
        // Zeile fallen.
        @file_put_contents($this->file, $zeile . "\n", \FILE_APPEND | \LOCK_EX);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function sanitize(array $context): array
    {
        $sauber = [];

        foreach ($context as $key => $value) {
            if (\in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                $sauber[$key] = '[entfernt]';

                continue;
            }

            $sauber[$key] = match (true) {
                \is_array($value) => $this->sanitize(array_filter($value, 'is_string', \ARRAY_FILTER_USE_KEY)),
                \is_scalar($value), $value === null => $this->shorten($value),
                $value instanceof DateTimeInterface => $value->format('c'),
                default => get_debug_type($value),
            };
        }

        return $sauber;
    }

    private function shorten(bool|float|int|string|null $value): bool|float|int|string|null
    {
        return \is_string($value) && \strlen($value) > self::MAX_VALUE_LENGTH
            ? substr($value, 0, self::MAX_VALUE_LENGTH) . '…'
            : $value;
    }
}
