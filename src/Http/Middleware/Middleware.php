<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Middleware;

use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;

/**
 * Bewusst PSR-15-nah gehalten (siehe docs/ARCHITEKTUR.md): Ein spaeterer
 * Umstieg auf Slim soll die Middleware nicht umschreiben muessen.
 */
interface Middleware
{
    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response;
}
