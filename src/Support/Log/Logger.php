<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support\Log;

/**
 * Strukturierte Protokollierung.
 *
 * Bewusst getrennt vom Audit-Trail: Das Protokoll haelt fest, was der Technik
 * passiert ist — Fehler, Laufzeiten, Jobs. Der Audit-Trail haelt fest, was
 * fachlich entschieden wurde, ist revisionssicher und darf nie rotiert werden.
 * Wer beides vermischt, hat entweder ein unbrauchbares Protokoll oder einen
 * wertlosen Audit-Trail.
 *
 * Kein PSR-3: Die Schnittstelle waere groesser als der Bedarf, und ein
 * Fremdpaket im Fehlerweg will gepflegt sein.
 */
interface Logger
{
    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $event, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $event, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $event, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $event, array $context = []): void;
}
