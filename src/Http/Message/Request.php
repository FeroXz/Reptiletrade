<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Message;

/**
 * Eingehende Anfrage. Bewusst kein PSR-7: ohne Streams, ohne Immutability-Zeremonie,
 * aber mit derselben Semantik. Die Schnittstelle bleibt PSR-15-nah, damit ein
 * spaeterer Umstieg auf Slim die Controller nicht anfasst (siehe docs/ARCHITEKTUR.md).
 */
final class Request
{
    /**
     * @param array<string, string|list<string>> $query
     * @param array<string, mixed>               $body
     * @param array<string, string>              $headers
     * @param array<string, string>              $attributes Vom Router gefuellte Pfadparameter
     * @param array<string, string>              $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        private array $attributes = [],
        public readonly array $cookies = [],
        public readonly ?string $clientIp = null,
    ) {}

    public static function fromGlobals(): self
    {
        /** @var array<string, string> $server */
        $server = $_SERVER;

        $uri = $server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, \PHP_URL_PATH);
        if (!\is_string($path) || $path === '') {
            $path = '/';
        }

        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        /** @var array<string, string|list<string>> $query */
        $query = $_GET;
        /** @var array<string, mixed> $body */
        $body = $_POST;
        /** @var array<string, string> $cookies */
        $cookies = $_COOKIE;

        return new self(
            strtoupper($server['REQUEST_METHOD'] ?? 'GET'),
            rawurldecode($path),
            $query,
            $body,
            $headers,
            [],
            $cookies,
            $server['REMOTE_ADDR'] ?? null,
        );
    }

    public function attribute(string $name, ?string $default = null): ?string
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * @param array<string, string> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        $clone = clone $this;
        $clone->attributes = $attributes + $this->attributes;

        return $clone;
    }

    public function queryString(string $name, ?string $default = null): ?string
    {
        $value = $this->query[$name] ?? null;
        if (\is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (!\is_string($value)) {
            return $default;
        }

        $value = trim($value);

        return $value === '' ? $default : $value;
    }

    public function queryInt(string $name, ?int $default = null): ?int
    {
        $value = $this->queryString($name);

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    public function queryBool(string $name): bool
    {
        $value = $this->queryString($name);

        return $value !== null && \in_array(strtolower($value), ['1', 'true', 'ja', 'on'], true);
    }

    /**
     * Mehrfach gesetzte Parameter, z. B. ?sex=m&sex=w oder ?sex[]=m&sex[]=w.
     *
     * @return list<string>
     */
    public function queryList(string $name): array
    {
        $value = $this->query[$name] ?? null;

        if (\is_string($value)) {
            $value = explode(',', $value);
        }

        if (!\is_array($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $entry) {
            if (\is_string($entry) && trim($entry) !== '') {
                $values[] = trim($entry);
            }
        }

        return $values;
    }

    public function wantsJson(): bool
    {
        $accept = $this->headers['accept'] ?? '';

        return str_starts_with($this->path, '/api/') || str_contains($accept, 'application/json');
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }
}
