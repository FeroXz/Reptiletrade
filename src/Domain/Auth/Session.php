<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Auth;

use DateTimeImmutable;

/**
 * Sitzung aus der Datenbank, nicht aus dem Dateisystem: So laesst sie sich
 * serveruebergreifend beenden, im Profil als Geraet anzeigen und per Job
 * aufraeumen.
 */
final readonly class Session
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $id,
        public ?int $userId,
        public array $payload,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastSeenAt,
        public DateTimeImmutable $expiresAt,
        public bool $twoFactorPending = false,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {}

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function isAuthenticated(): bool
    {
        return $this->userId !== null && !$this->twoFactorPending;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    /**
     * Grobe Geraetebezeichnung fuer die Sitzungsliste.
     */
    public function deviceLabel(): string
    {
        return DeviceFingerprint::label($this->userAgent);
    }
}
