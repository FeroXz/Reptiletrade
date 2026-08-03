<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http;

use RuntimeException;

/**
 * Fehler mit einem bewussten HTTP-Status. Alles andere wird zu 500.
 */
final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Nicht gefunden'): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = 'Kein Zugriff'): self
    {
        return new self(403, $message);
    }

    public static function badRequest(string $message = 'Ungültige Anfrage'): self
    {
        return new self(400, $message);
    }
}
