<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Routing;

final readonly class RouteMatch
{
    /**
     * @param array<string, string> $attributes
     */
    public function __construct(
        public Route $route,
        public array $attributes,
    ) {}
}
