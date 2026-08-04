<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

use DateTimeImmutable;

interface UserRepository
{
    public function findById(int $id): ?User;

    public function findByEmail(string $email): ?User;

    /**
     * Liefert den gespeicherten Passwort-Hash. Bewusst getrennt von der
     * User-Entitaet: Der Hash soll nicht versehentlich in einem Template
     * oder einer API-Antwort landen.
     */
    public function passwordHashFor(int $userId): ?string;

    public function create(User $user, string $passwordHash): int;

    public function updatePasswordHash(int $userId, string $passwordHash): void;

    public function recordSuccessfulLogin(int $userId): void;

    /**
     * @return int Anzahl der Fehlversuche nach dem Hochzaehlen
     */
    public function recordFailedLogin(int $userId): int;

    public function lockUntil(int $userId, string $until): void;

    public function lockedUntil(int $userId): ?string;

    /**
     * Kennzahlen fuer die Gewerblichkeitsregel der Rechts-Engine.
     *
     * @return array{aktive_anzeigen: int, verkaeufe_12_monate: int}
     */
    public function salesStatistics(int $userId): array;

    /**
     * Wie viele Anzeigen dieses Konto je veroeffentlicht hat — Eingangsgroesse
     * der Auto-Moderation neuer Konten.
     */
    public function publishedListingCount(int $userId): int;

    public function findByDisplayName(string $displayName): ?User;

    /**
     * Sperrt ein Konto. $until ist null bei einer unbefristeten Sperre.
     */
    public function ban(int $userId, DateTimeImmutable $at, ?DateTimeImmutable $until, string $reason, ?int $byUserId): void;

    public function unban(int $userId): void;

    public function banState(int $userId): ?BanState;

    /**
     * Konten, deren befristete Sperre abgelaufen ist.
     *
     * @return list<User>
     */
    public function withExpiredBan(DateTimeImmutable $now): array;

    /**
     * Liste fuer die Verwaltung.
     *
     * @param array{status?: string, suche?: string, nur_gesperrt?: bool} $filters
     *
     * @return list<AdminUserRow>
     */
    public function forAdmin(array $filters = [], int $limit = 100): array;
}
