<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Message;

final readonly class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {}

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['content-type' => 'text/html; charset=utf-8']);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            $status,
            ['content-type' => 'application/json; charset=utf-8'],
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['location' => $location]);
    }

    public static function notFound(string $body = 'Nicht gefunden'): self
    {
        return self::html($body, 404);
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[strtolower($name)] = $value;

        return new self($this->body, $this->status, $headers);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        echo $this->body;
    }
}
