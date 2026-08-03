<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http;

use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Middleware\Middleware;
use Reptilienmarkt\Http\Routing\Router;
use Reptilienmarkt\Http\Session\NotAuthenticatedException;
use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Log\Logger;
use Reptilienmarkt\Support\Log\NullLogger;
use Throwable;

/**
 * Nimmt eine Anfrage entgegen, arbeitet die Middleware-Kette ab, sucht die
 * Route und ruft den Controller auf.
 */
final readonly class Kernel
{
    /**
     * @param list<Middleware> $middleware
     */
    public function __construct(
        private Router $router,
        private Container $container,
        private array $middleware = [],
        private bool $debug = false,
        private Logger $logger = new NullLogger(),
    ) {}

    public function handle(Request $request): Response
    {
        // Die Fehlerbehandlung sitzt *innerhalb* der Middleware-Kette, nicht
        // darum herum. Sonst verlaesst eine Ausnahme die Kette nach oben, und
        // was die Middleware danach tun wollte, faellt aus — allen voran das
        // Setzen des Sitzungs-Cookies. Eine Fehlerseite ohne Cookie ist
        // heimtueckisch: Der naechste Versuch scheitert genauso, weil die
        // Sitzung nie beim Browser ankam.
        $handler = function (Request $request): Response {
            try {
                return $this->dispatch($request);
            } catch (Throwable $exception) {
                return $this->handleException($request, $exception);
            }
        };

        foreach (array_reverse($this->middleware) as $middleware) {
            $next = $handler;
            $handler = static fn(Request $request): Response => $middleware->process($request, $next);
        }

        try {
            return $this->withSecurityHeaders($handler($request));
        } catch (Throwable $exception) {
            // Hier landet nur noch, was in der Middleware selbst schiefgeht.
            return $this->withSecurityHeaders($this->handleException($request, $exception));
        }
    }

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request);

        if ($match === null) {
            return $this->router->pathExists($request->path)
                ? $this->error($request, 405, 'Methode nicht erlaubt')
                : $this->error($request, 404, 'Seite nicht gefunden');
        }

        $controller = $this->container->get($match->route->controller);
        $action = $match->route->action;

        if (!\is_object($controller) || !method_exists($controller, $action)) {
            return $this->error($request, 500, 'Controller nicht aufrufbar');
        }

        /** @var Response $response */
        $response = $controller->{$action}($request->withAttributes($match->attributes));

        return $response;
    }

    private function handleException(Request $request, Throwable $exception): Response
    {
        if ($exception instanceof NotAuthenticatedException) {
            if ($request->wantsJson()) {
                return Response::json(['fehler' => $exception->getMessage()], 401);
            }

            return Response::redirect('/anmelden?weiter=' . rawurlencode($request->path));
        }

        if ($exception instanceof HttpException) {
            return $this->error($request, $exception->status, $exception->getMessage());
        }

        $this->logError($request, $exception);

        if ($this->debug) {
            return Response::html(
                '<pre>' . htmlspecialchars(
                    $exception::class . ': ' . $exception->getMessage() . "\n\n" . $exception->getTraceAsString(),
                    \ENT_QUOTES,
                ) . '</pre>',
                500,
            );
        }

        return $this->error($request, 500, 'Interner Fehler');
    }

    /**
     * Fehler gehen ins Anwendungsprotokoll, nicht in den Audit-Trail: Der eine
     * dokumentiert Technik, der andere fachliche Entscheidungen. Wer beides
     * vermischt, hat entweder ein unbrauchbares Protokoll oder einen wertlosen
     * Nachweis.
     */
    private function logError(Request $request, Throwable $exception): void
    {
        $this->logger->error('http.unhandled_exception', [
            'methode' => $request->method,
            'pfad' => $request->path,
            'ausnahme' => $exception::class,
            'meldung' => $exception->getMessage(),
            'datei' => $exception->getFile() . ':' . $exception->getLine(),
            'ip' => $request->clientIp,
        ]);
    }

    private function error(Request $request, int $status, string $message): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['fehler' => $message], $status);
        }

        return Response::html(
            '<!doctype html><meta charset="utf-8"><title>' . $status . '</title>'
            . '<style>body{font:16px/1.6 system-ui;margin:4rem auto;max-width:40rem;padding:0 1rem}</style>'
            . '<h1>' . $status . '</h1><p>' . htmlspecialchars($message, \ENT_QUOTES) . '</p>'
            . '<p><a href="/markt/">Zur Marktübersicht</a></p>',
            $status,
        );
    }

    private function withSecurityHeaders(Response $response): Response
    {
        $response = $response
            ->withHeader('x-content-type-options', 'nosniff')
            ->withHeader('referrer-policy', 'strict-origin-when-cross-origin')
            ->withHeader('x-frame-options', 'DENY');

        // HTML-Seiten sind hier nie allgemeingueltig: Die Kopfzeile zeigt den
        // angemeldeten Namen, Formulare tragen einen sitzungsgebundenen
        // CSRF-Token. Legt ein vorgelagerter Zwischenspeicher so eine Seite ab,
        // bekommt der naechste Besucher einen fremden Token — und damit bei
        // jedem Absenden "Das Formular ist abgelaufen". Statische Dateien
        // liefert der Webserver aus, die sind davon nicht betroffen.
        $contentType = $response->headers['content-type'] ?? '';

        if (\is_string($contentType) && str_contains($contentType, 'text/html')
            && !isset($response->headers['cache-control'])) {
            $response = $response->withHeader('cache-control', 'private, no-store');
        }

        return $response;
    }
}
