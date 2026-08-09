<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Message;

use DateTimeImmutable;

interface MessageRepository
{
    public function findById(int $id): ?Message;

    /**
     * @return list<Message>
     */
    public function forConversation(int $conversationId, int $limit = 200): array;

    public function add(Message $message): int;

    /**
     * Markiert alles als gelesen, was der Nutzer nicht selbst geschrieben hat.
     *
     * @return int Anzahl der betroffenen Nachrichten
     */
    public function markRead(int $conversationId, int $readerId, DateTimeImmutable $at): int;

    /**
     * Wie viele Nachrichten dieses Gespraechs der Leser noch nicht gesehen hat.
     *
     * $exceptId blendet eine Nachricht aus — beim Benachrichtigen die gerade
     * geschriebene, denn die Frage lautet "wusste er schon von etwas?" und
     * nicht "gibt es etwas?".
     */
    public function unreadCountInConversation(int $conversationId, int $readerId, ?int $exceptId = null): int;

    /**
     * Markierte Nachrichten fuer die Moderationsliste, neueste zuerst.
     *
     * @return list<Message>
     */
    public function flagged(int $limit = 50): array;
}
