<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http;

use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Middleware\Middleware;
use Reptilienmarkt\Http\Routing\Router;
use Reptilienmarkt\Http\Session\NotAuthenticatedException;
use Reptilienmarkt\Support\Container;
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
    ) {}

    public function handle(Request $request): Response
    {
        $handler = function (Request $request): Response {
            return $this->dispatch($request);
        };

        foreach (array_reverse($this->middleware) as $middleware) {
            $next = $handler;
            $handler = static fn(Request $request): Response => $middleware->process($request, $next);
        }

        try {
            return $this->withSecurityHeaders($handler($request));
        } catch (Throwable $exception) {
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

    private function logError(Request $request, Throwable $exception): void
    {
        error_log(json_encode([
            'zeitpunkt' => gmdate('c'),
            'stufe' => 'error',
            'pfad' => $request->path,
            'ausnahme' => $exception::class,
            'meldung' => $exception->getMessage(),
            'datei' => $exception->getFile() . ':' . $exception->getLine(),
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: $exception->getMessage());
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
        return $response
            ->withHeader('x-content-type-options', 'nosniff')
            ->withHeader('referrer-policy', 'strict-origin-when-cross-origin')
            ->withHeader('x-frame-options', 'DENY');
    }
}
