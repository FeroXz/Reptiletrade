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
     * @param array<string, UploadedFile>        $files
     * @param ?string                            $rawBody Ungeparster Rumpf — nur wo er gebraucht wird
     * @param bool                               $secure  Kam die Anfrage ueber TLS herein?
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
        public readonly array $files = [],
        public readonly ?string $rawBody = null,
        public readonly bool $secure = false,
    ) {}

    /**
     * Der ungeparste Rumpf. Fuer Signaturpruefungen unverzichtbar: Ein
     * dekodiertes und neu zusammengesetztes JSON ergibt nicht mehr dieselben
     * Bytes, und damit stimmt keine Signatur mehr.
     */
    public function raw(): string
    {
        return $this->rawBody ?? '';
    }

    public function file(string $name): ?UploadedFile
    {
        return $this->files[$name] ?? null;
    }

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

        $files = [];
        /** @var array<string, array<string, mixed>> $uploads */
        $uploads = $_FILES;
        foreach ($uploads as $name => $entry) {
            $file = UploadedFile::fromGlobalEntry($entry);
            if ($file !== null) {
                $files[$name] = $file;
            }
        }

        // Nur lesen, wo es gebraucht wird: php://input laesst sich nicht
        // zweimal lesen, und bei Uploads waere es die ganze Datei.
        $contentType = $headers['content-type'] ?? '';
        $raw = str_contains($contentType, 'json') ? (file_get_contents('php://input') ?: '') : null;

        return new self(
            strtoupper($server['REQUEST_METHOD'] ?? 'GET'),
            rawurldecode($path),
            $query,
            $body,
            $headers,
            [],
            $cookies,
            $server['REMOTE_ADDR'] ?? null,
            $files,
            $raw,
            self::detectSecure($server, $headers),
        );
    }

    /**
     * Laeuft die Verbindung ueber TLS?
     *
     * Daran haengt das Secure-Flag des Sitzungs-Cookies. Es aus der
     * tatsaechlichen Verbindung abzuleiten statt aus einer Einstellung ist der
     * Unterschied zwischen "funktioniert ueberall" und "funktioniert, solange
     * jemand daran gedacht hat": Ein Secure-Cookie auf einer HTTP-Seite wird
     * vom Browser verworfen — und ohne Cookie gibt es keine Sitzung, ohne
     * Sitzung keinen CSRF-Token, und jedes Formular endet mit
     * "Das Formular ist abgelaufen".
     *
     * Die Weiterleitungs-Kopfzeilen eines vorgelagerten Proxys werden dabei
     * geglaubt. Das ist hier ungefaehrlich: Wer sie faelscht, faelscht sie in
     * seiner eigenen Anfrage und beschaedigt hoechstens seine eigene Sitzung.
     * An das Cookie eines anderen kommt er dadurch nicht.
     *
     * @param array<string, string> $server
     * @param array<string, string> $headers
     */
    private static function detectSecure(array $server, array $headers): bool
    {
        $https = strtolower($server['HTTPS'] ?? '');

        if ($https !== '' && $https !== 'off') {
            return true;
        }

        if (($server['REQUEST_SCHEME'] ?? '') === 'https' || ($server['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        $forwarded = strtolower($headers['x-forwarded-proto'] ?? '');

        if ($forwarded !== '') {
            // Bei mehreren Proxys steht hier eine Liste; der erste Eintrag ist
            // der Browser.
            return str_starts_with(trim(explode(',', $forwarded)[0]), 'https');
        }

        return strtolower($headers['x-forwarded-ssl'] ?? '') === 'on';
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
