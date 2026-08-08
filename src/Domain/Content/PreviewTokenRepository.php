<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use DateTimeImmutable;

interface PreviewTokenRepository
{
    /**
     * Legt einen Vorschaulink an. Gespeichert wird nur der Hash — der Klartext
     * wandert einmal zum Empfaenger und ist danach nirgends mehr abrufbar.
     */
    public function store(string $tokenHash, int $entryId, DateTimeImmutable $expiresAt, ?int $createdBy, DateTimeImmutable $now): void;

    /**
     * @return int|null die ID des Eintrags, oder null bei unbekanntem oder abgelaufenem Token
     */
    public function resolve(string $tokenHash, DateTimeImmutable $now): ?int;

    /**
     * Raeumt abgelaufene Links ab.
     */
    public function purgeExpired(DateTimeImmutable $before): int;
}
