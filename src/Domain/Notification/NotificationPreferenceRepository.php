<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Notification;

use DateTimeImmutable;

interface NotificationPreferenceRepository
{
    /**
     * Die abweichenden Einstellungen eines Kontos. Kanaele ohne Zeile stehen
     * auf der Voreinstellung und fehlen hier.
     *
     * @return array<string, bool> Kanalschluessel => eingeschaltet
     */
    public function forUser(int $userId): array;

    public function set(int $userId, NotificationChannel $channel, bool $enabled, DateTimeImmutable $at): void;

    /**
     * Legt den Hash des Abmeldetokens ab und entwertet damit den vorigen.
     */
    public function storeUnsubscribeHash(int $userId, string $hash, DateTimeImmutable $at): void;

    public function findUserIdByUnsubscribeHash(string $hash): ?int;
}
