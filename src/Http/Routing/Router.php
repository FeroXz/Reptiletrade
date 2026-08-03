<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Routing;

use Reptilienmarkt\Http\Message\Request;

/**
 * Schlanker Front-Controller-Router. Die Begruendung gegen Slim 4 steht in
 * docs/ARCHITEKTUR.md.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * @param class-string $controller
     */
    public function get(string $pattern, string $controller, string $action, string $name): void
    {
        $this->add(['GET', 'HEAD'], $pattern, $controller, $action, $name);
    }

    /**
     * @param class-string $controller
     */
    public function post(string $pattern, string $controller, string $action, string $name): void
    {
        $this->add(['POST'], $pattern, $controller, $action, $name);
    }

    /**
     * @param list<string> $methods
     * @param class-string $controller
     */
    public function add(array $methods, string $pattern, string $controller, string $action, string $name): void
    {
        $this->routes[] = new Route($methods, $pattern, $controller, $action, $name);
    }

    public function match(Request $request): ?RouteMatch
    {
        foreach ($this->routes as $route) {
            $attributes = $route->match($request->method, $request->path);
            if ($attributes !== null) {
                return new RouteMatch($route, $attributes);
            }
        }

        return null;
    }

    /**
     * Gibt es den Pfad ueberhaupt, nur nicht mit dieser Methode? Dann ist 405
     * die richtige Antwort, nicht 404.
     */
    public function pathExists(string $path): bool
    {
        foreach ($this->routes as $route) {
            if ($route->matchesPath($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }
}
