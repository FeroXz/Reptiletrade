<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Middleware;

use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;

/**
 * Startet die Sitzung vor dem Controller und schreibt sie danach zurueck.
 */
final readonly class SessionMiddleware implements Middleware
{
    public function __construct(private SessionManager $session) {}

    public function process(Request $request, callable $next): Response
    {
        $this->session->start($request);

        try {
            $response = $next($request);
        } finally {
            // Auch im Fehlerfall schreiben: Sonst geht eine Flash-Meldung
            // oder ein frisch vergebener CSRF-Token verloren.
            $this->session->commit();
        }

        $cookie = $this->session->cookieHeader();

        return $cookie === null ? $response : $response->withHeader('set-cookie', $cookie);
    }
}
