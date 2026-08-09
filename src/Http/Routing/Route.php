<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Routing;

/**
 * Eine Route. Das Muster kennt zwei Platzhalter: {name} passt auf genau ein
 * Pfadsegment, {name*} auf mehrere. Der zweite wird von der Catch-all-Route des
 * Redaktionssystems gebraucht — siehe docs/CMS.md.
 */
final readonly class Route
{
    public string $regex;

    /** @var list<string> */
    public array $parameters;

    /**
     * @param list<string> $methods
     * @param class-string $controller
     * @param bool         $fallback Faengt diese Route alles ab, was vorher nicht griff?
     */
    public function __construct(
        public array $methods,
        public string $pattern,
        public string $controller,
        public string $action,
        public string $name,
        public bool $fallback = false,
    ) {
        preg_match_all('/\{([a-z_]+)\*?\}/', $pattern, $matches);
        $this->parameters = $matches[1];

        $regex = preg_quote($pattern, '#');

        // {name*} nimmt mehrere Segmente. Gebraucht wird das genau einmal: von
        // der Catch-all-Route des Redaktionssystems, die als letzte steht und
        // /ueber-uns/team/ ebenso treffen muss wie /haltung/. Die Alternative
        // waere eine Sonderbehandlung im Kernel gewesen — dann stuende eine
        // Route nicht mehr dort, wo alle anderen stehen.
        $regex = (string) preg_replace('/\\\\\{([a-z_]+)\\\\\*\\\\\}/', '(?P<$1>.+)', $regex);
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
