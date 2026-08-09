<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Message;

use DateTimeImmutable;

/**
 * Ein Gespraech zwischen Interessent und Anbieter, immer an eine Anzeige
 * gebunden. Pro Anzeige und Interessent gibt es genau eines — das erzwingt
 * schon der eindeutige Index in der Datenbank.
 */
final readonly class Conversation
{
    public function __construct(
        public ?int $id,
        public int $listingId,
        public int $buyerId,
        public int $sellerId,
        public ConversationStatus $status = ConversationStatus::Offen,
        public ?DateTimeImmutable $dealConfirmedBuyerAt = null,
        public ?DateTimeImmutable $dealConfirmedSellerAt = null,
        public int $messageCount = 0,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $lastMessageAt = null,
        public ?DateTimeImmutable $notifiedBuyerAt = null,
        public ?DateTimeImmutable $notifiedSellerAt = null,
    ) {}

    public function involves(int $userId): bool
    {
        return $this->buyerId === $userId || $this->sellerId === $userId;
    }

    /**
     * Wann diese Seite zuletzt eine Mail zu diesem Gespraech bekommen hat.
     */
    public function notifiedAtFor(int $userId): ?DateTimeImmutable
    {
        return $this->isBuyer($userId) ? $this->notifiedBuyerAt : $this->notifiedSellerAt;
    }

    public function counterpartOf(int $userId): int
    {
        return $userId === $this->buyerId ? $this->sellerId : $this->buyerId;
    }

    public function isBuyer(int $userId): bool
    {
        return $this->buyerId === $userId;
    }

    /**
     * Bewertungen sind erst moeglich, wenn beide Seiten den Handel bestaetigt
     * haben. Eine einseitige Bestaetigung reicht nicht — sonst koennte eine
     * Seite den Handel behaupten und bewerten, ohne dass er stattfand.
     */
    public function dealConfirmed(): bool
    {
        return $this->dealConfirmedBuyerAt !== null && $this->dealConfirmedSellerAt !== null;
    }

    public function dealConfirmedAt(): ?DateTimeImmutable
    {
        if (!$this->dealConfirmed()) {
            return null;
        }

        // Der spaetere der beiden Zeitpunkte ist der, an dem der Handel stand.
        return $this->dealConfirmedBuyerAt > $this->dealConfirmedSellerAt
            ? $this->dealConfirmedBuyerAt
            : $this->dealConfirmedSellerAt;
    }

    public function hasConfirmed(int $userId): bool
    {
        return $this->isBuyer($userId)
            ? $this->dealConfirmedBuyerAt !== null
            : $this->dealConfirmedSellerAt !== null;
    }
}
