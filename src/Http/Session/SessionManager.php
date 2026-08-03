<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Session;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Auth\Session;
use Reptilienmarkt\Domain\Auth\SessionRepository;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Support\Clock;
use RuntimeException;

/**
 * Sitzungsverwaltung ueber die Tabelle sessions — bewusst nicht ueber
 * PHP-Filesessions. Pro Anfrage eine Instanz.
 */
final class SessionManager
{
    public const string COOKIE_NAME = 'rm_session';

    private const string CSRF_KEY = '_csrf';

    private const string FLASH_KEY = '_flash';

    private ?Session $session = null;

    private bool $cookieMustBeSent = false;

    public function __construct(
        private readonly SessionRepository $repository,
        private readonly Clock $clock,
        private readonly int $lifetimeMinutes = 1440,
        private readonly bool $secureCookie = true,
    ) {}

    public function start(Request $request): void
    {
        $id = $request->cookies[self::COOKIE_NAME] ?? null;
        $now = $this->clock->now();

        if (\is_string($id) && $id !== '') {
            $existing = $this->repository->find($id);

            if ($existing !== null && !$existing->isExpired($now)) {
                $this->session = new Session(
                    $existing->id,
                    $existing->userId,
                    $existing->payload,
                    $existing->createdAt,
                    $now,
                    $this->expiry(),
                    $existing->twoFactorPending,
                    $request->clientIp ?? $existing->ipAddress,
                    $request->headers['user-agent'] ?? $existing->userAgent,
                );

                return;
            }

            if ($existing !== null) {
                $this->repository->delete($existing->id);
            }
        }

        $this->session = $this->fresh(null, $request);
        $this->cookieMustBeSent = true;
    }

    public function id(): string
    {
        return $this->current()->id;
    }

    public function userId(): ?int
    {
        $session = $this->current();

        return $session->isAuthenticated() ? $session->userId : null;
    }

    public function isAuthenticated(): bool
    {
        return $this->userId() !== null;
    }

    /**
     * Meldet einen Nutzer an. Die Sitzungskennung wird dabei neu vergeben —
     * sonst liesse sich eine vorher untergeschobene Kennung weiterverwenden
     * (Session Fixation).
     */
    public function login(int $userId): void
    {
        $old = $this->current();
        $this->repository->delete($old->id);

        $this->session = new Session(
            self::newId(),
            $userId,
            // Der CSRF-Token wird bewusst mitgenommen: Das offene Formular
            // der Anmeldeseite soll nach dem Login nicht ungueltig werden.
            array_intersect_key($old->payload, [self::CSRF_KEY => true]),
            $this->clock->now(),
            $this->clock->now(),
            $this->expiry(),
            false,
            $old->ipAddress,
            $old->userAgent,
        );

        $this->cookieMustBeSent = true;
    }

    public function logout(): void
    {
        $session = $this->current();
        $this->repository->delete($session->id);

        $this->session = $this->fresh(null, null, $session->ipAddress, $session->userAgent);
        $this->cookieMustBeSent = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->current()->payload[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $session = $this->current();
        $payload = $session->payload;
        $payload[$key] = $value;

        $this->session = $this->withPayload($session, $payload);
    }

    public function forget(string $key): void
    {
        $session = $this->current();
        $payload = $session->payload;
        unset($payload[$key]);

        $this->session = $this->withPayload($session, $payload);
    }

    /**
     * Einmalmeldung fuer die naechste Antwort.
     */
    public function flash(string $type, string $message): void
    {
        /** @var array<string, string> $flashes */
        $flashes = \is_array($this->get(self::FLASH_KEY)) ? $this->get(self::FLASH_KEY) : [];
        $flashes[$type] = $message;

        $this->put(self::FLASH_KEY, $flashes);
    }

    /**
     * @return array<string, string>
     */
    public function takeFlashes(): array
    {
        /** @var mixed $flashes */
        $flashes = $this->get(self::FLASH_KEY);
        $this->forget(self::FLASH_KEY);

        if (!\is_array($flashes)) {
            return [];
        }

        $result = [];
        foreach ($flashes as $type => $message) {
            if (\is_string($type) && \is_string($message)) {
                $result[$type] = $message;
            }
        }

        return $result;
    }

    public function csrfToken(): string
    {
        $token = $this->get(self::CSRF_KEY);

        if (!\is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->put(self::CSRF_KEY, $token);
        }

        return $token;
    }

    public function verifyCsrf(?string $token): bool
    {
        $expected = $this->get(self::CSRF_KEY);

        if (!\is_string($expected) || $expected === '' || $token === null) {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public function commit(): void
    {
        if ($this->session !== null) {
            $this->repository->save($this->session);
        }
    }

    public function cookieHeader(): ?string
    {
        if (!$this->cookieMustBeSent || $this->session === null) {
            return null;
        }

        $parts = [
            self::COOKIE_NAME . '=' . $this->session->id,
            'Path=/',
            'Max-Age=' . ($this->lifetimeMinutes * 60),
            'HttpOnly',
            'SameSite=Lax',
        ];

        if ($this->secureCookie) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function current(): Session
    {
        if ($this->session === null) {
            throw new RuntimeException('Die Sitzung wurde nicht gestartet.');
        }

        return $this->session;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function withPayload(Session $session, array $payload): Session
    {
        return new Session(
            $session->id,
            $session->userId,
            $payload,
            $session->createdAt,
            $this->clock->now(),
            $session->expiresAt,
            $session->twoFactorPending,
            $session->ipAddress,
            $session->userAgent,
        );
    }

    private function fresh(?int $userId, ?Request $request, ?string $ip = null, ?string $agent = null): Session
    {
        $now = $this->clock->now();

        return new Session(
            self::newId(),
            $userId,
            [],
            $now,
            $now,
            $this->expiry(),
            false,
            $request === null ? $ip : ($request->clientIp ?? $ip),
            $request === null ? $agent : ($request->headers['user-agent'] ?? $agent),
        );
    }

    private function expiry(): DateTimeImmutable
    {
        return $this->clock->now()->modify(\sprintf('+%d minutes', $this->lifetimeMinutes));
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(32));
    }
}
