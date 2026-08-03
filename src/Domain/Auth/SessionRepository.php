<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Auth;

interface SessionRepository
{
    public function find(string $id): ?Session;

    public function save(Session $session): void;

    public function delete(string $id): void;

    /**
     * Beendet alle Sitzungen eines Nutzers — nach Passwortwechsel oder Sperrung.
     */
    public function deleteForUser(int $userId): void;

    /**
     * @return int Anzahl geloeschter Sitzungen
     */
    public function deleteExpired(): int;
}
