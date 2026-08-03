<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Routing;

/**
 * Eine Route. Das Muster kennt Platzhalter der Form {name}, die auf ein
 * Pfadsegment ohne Schraegstrich passen.
 */
final readonly class Route
{
    public string $regex;

    /** @var list<string> */
    public array $parameters;

    /**
     * @param list<string> $methods
     * @param class-string $controller
     */
    public function __construct(
        public array $methods,
        public string $pattern,
        public string $controller,
        public string $action,
        public string $name,
    ) {
        preg_match_all('/\{([a-z_]+)\}/', $pattern, $matches);
        $this->parameters = $matches[1];

        $regex = preg_quote($pattern, '#');
        $regex = (string) preg_replace('/\\\\\{([a-z_]+)\\\\\}/', '(?P<$1>[^/]+)', $regex);

        $this->regex = '#^' . $regex . '$#u';
    }

    /**
     * @return array<string, string>|null
     */
    public function match(string $method, string $path): ?array
    {
        if (!\in_array($method, $this->methods, true)) {
            return null;
        }

        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }

        $attributes = [];
        foreach ($this->parameters as $parameter) {
            $attributes[$parameter] = $matches[$parameter] ?? '';
        }

        return $attributes;
    }

    public function matchesPath(string $path): bool
    {
        return preg_match($this->regex, $path) === 1;
    }
}
