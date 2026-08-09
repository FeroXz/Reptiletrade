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

    /**
     * Die Auffangroute: greift, was bis dahin niemand beansprucht hat.
     *
     * Sie ist getrennt von get(), weil sie eine Eigenschaft hat, die keine
     * andere Route hat — sie passt auf jeden Pfad. Fuer pathExists() ist das
     * bedeutsam: Wer sie mitzaehlte, bekaeme auf ein POST an eine beliebige
     * Adresse ein 405 statt eines 404, weil der Pfad ja "existiert".
     *
     * @param class-string $controller
     */
    public function fallback(string $pattern, string $controller, string $action, string $name): void
    {
        $this->routes[] = new Route(['GET', 'HEAD'], $pattern, $controller, $action, $name, fallback: true);
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
            if ($route->fallback) {
                continue;
            }

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
