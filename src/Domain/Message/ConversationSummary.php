<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Message;

use DateTimeImmutable;

/**
 * Eine Zeile im Postfach. Bewusst kein vollstaendiges Conversation-Objekt:
 * Die Liste braucht Titel, Gegenueber und Zaehler, aber keine Handelsdaten —
 * und sie soll mit einer einzigen Abfrage auskommen.
 */
final readonly class ConversationSummary
{
    public function __construct(
        public int $id,
        public int $listingId,
        public string $listingTitle,
        public ?string $listingImagePath,
        public int $counterpartId,
        public string $counterpartName,
        public ConversationStatus $status,
        public int $messageCount,
        public int $unreadCount,
        public ?string $lastMessagePreview,
        public ?DateTimeImmutable $lastMessageAt,
        public bool $dealConfirmed,
        public bool $ownConfirmation,
    ) {}

    public function hasUnread(): bool
    {
        return $this->unreadCount > 0;
    }
}
