<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

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
}
