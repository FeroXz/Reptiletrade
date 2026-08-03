<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Auth;

use DateTimeImmutable;

interface TokenRepository
{
    /**
     * Legt einen Token ab. Gespeichert wird nur der Hash — wer die Datenbank
     * liest, kann damit kein Konto uebernehmen.
     */
    public function store(int $userId, TokenType $type, string $tokenHash, DateTimeImmutable $expiresAt): int;

    public function findUnusedByHash(string $tokenHash): ?TokenRecord;

    public function markUsed(int $tokenId, DateTimeImmutable $at): void;

    /**
     * Entwertet alle offenen Token eines Typs. Wird beim Ausstellen eines neuen
     * aufgerufen: Zwei gueltige Reset-Links gleichzeitig soll es nicht geben.
     */
    public function invalidateAll(int $userId, TokenType $type, DateTimeImmutable $at): int;

    public function purgeExpired(DateTimeImmutable $before): int;
}
