<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Session;

use RuntimeException;

/**
 * Wird vom Kernel abgefangen und je nach Kanal in eine Weiterleitung zur
 * Anmeldung oder in eine 401 uebersetzt.
 */
final class NotAuthenticatedException extends RuntimeException
{
    public function __construct(string $message = 'Bitte melde dich an.')
    {
        parent::__construct($message);
    }
}
