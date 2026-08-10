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
     * Das konto-eigene Geheimnis, aus dem der Abmeldelink abgeleitet wird —
     * oder null, wenn das Konto keines hat.
     */
    public function unsubscribeSecret(int $userId): ?string;

    /**
     * Legt ein neues Geheimnis ab und entwertet damit **alle** bisherigen
     * Abmeldelinks des Kontos auf einen Schlag.
     */
    public function storeUnsubscribeSecret(int $userId, string $secret, DateTimeImmutable $at): void;
}
