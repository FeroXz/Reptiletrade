<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support\Log;

/**
 * Verschluckt alles — fuer Tests, die das Protokoll nicht pruefen.
 */
final readonly class NullLogger implements Logger
{
    public function debug(string $event, array $context = []): void {}

    public function info(string $event, array $context = []): void {}

    public function warning(string $event, array $context = []): void {}

    public function error(string $event, array $context = []): void {}
}
