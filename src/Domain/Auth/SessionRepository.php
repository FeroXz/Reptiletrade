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
     * Beendet alle Sitzungen eines Nutzers ausser einer — die, in der die
     * Massnahme ausgeloest wurde.
     *
     * @return int Anzahl beendeter Sitzungen
     */
    public function deleteForUserExcept(int $userId, string $keepId): int;

    /**
     * Die offenen Sitzungen eines Nutzers, zuletzt gesehene zuerst.
     *
     * @return list<Session>
     */
    public function forUser(int $userId): array;

    /**
     * @return int Anzahl geloeschter Sitzungen
     */
    public function deleteExpired(): int;
}
