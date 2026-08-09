<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Message;

use DateTimeImmutable;

interface ConversationRepository
{
    public function findById(int $id): ?Conversation;

    public function findForListingAndBuyer(int $listingId, int $buyerId): ?Conversation;

    public function create(Conversation $conversation): int;

    /**
     * Konversationen eines Nutzers, neueste zuerst.
     *
     * @return list<ConversationSummary>
     */
    public function inbox(int $userId, int $limit = 50, int $offset = 0): array;

    public function unreadCount(int $userId): int;

    public function confirmDeal(int $conversationId, bool $asBuyer, DateTimeImmutable $at): void;

    public function updateStatus(int $conversationId, ConversationStatus $status): void;

    /**
     * Zaehlt die Nachricht mit und setzt last_message_at.
     */
    public function registerMessage(int $conversationId, DateTimeImmutable $at): void;

    /**
     * Haelt fest, dass eine Seite ueber dieses Gespraech benachrichtigt wurde.
     */
    public function markNotified(int $conversationId, bool $forBuyer, DateTimeImmutable $at): void;
}
