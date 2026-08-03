<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http;

use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Routing\Router;
use Reptilienmarkt\Support\Container;
use Throwable;

/**
 * Nimmt eine Anfrage entgegen, sucht die Route, ruft den Controller auf.
 */
final readonly class Kernel
{
    public function __construct(
        private Router $router,
        private Container $container,
        private bool $debug = false,
    ) {}

    public function handle(Request $request): Response
    {
        try {
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

            return $this->withSecurityHeaders($response);
        } catch (Throwable $exception) {
            return $this->handleException($request, $exception);
        }
    }

    private function handleException(Request $request, Throwable $exception): Response
    {
        error_log(json_encode([
            'zeitpunkt' => gmdate('c'),
            'stufe' => 'error',
            'pfad' => $request->path,
            'ausnahme' => $exception::class,
            'meldung' => $exception->getMessage(),
            'datei' => $exception->getFile() . ':' . $exception->getLine(),
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: $exception->getMessage());

        if ($this->debug) {
            return $this->withSecurityHeaders(Response::html(
                '<pre>' . htmlspecialchars(
                    $exception::class . ': ' . $exception->getMessage() . "\n\n" . $exception->getTraceAsString(),
                    \ENT_QUOTES,
                ) . '</pre>',
                500,
            ));
        }

        return $this->error($request, 500, 'Interner Fehler');
    }

    private function error(Request $request, int $status, string $message): Response
    {
        $response = $request->wantsJson()
            ? Response::json(['fehler' => $message], $status)
            : Response::html(
                '<!doctype html><meta charset="utf-8"><title>' . $status . '</title>'
                . '<style>body{font:16px/1.6 system-ui;margin:4rem auto;max-width:40rem;padding:0 1rem}</style>'
                . '<h1>' . $status . '</h1><p>' . htmlspecialchars($message, \ENT_QUOTES) . '</p>'
                . '<p><a href="/markt/">Zur Marktübersicht</a></p>',
                $status,
            );

        return $this->withSecurityHeaders($response);
    }

    private function withSecurityHeaders(Response $response): Response
    {
        return $response
            ->withHeader('x-content-type-options', 'nosniff')
            ->withHeader('referrer-policy', 'strict-origin-when-cross-origin')
            ->withHeader('x-frame-options', 'DENY');
    }
}
