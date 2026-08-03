<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Message;

use DateTimeImmutable;

final readonly class Message
{
    /**
     * @param int $sequence laufende Nummer im Gespraech. Beim Anlegen ohne
     *                      Bedeutung — die Nummer vergibt die Datenbank
     *                      (siehe PdoMessageRepository::add). Beim Lesen ist
     *                      sie massgeblich, denn an ihr haengt die
     *                      Kontaktmaskierung.
     */
    public function __construct(
        public ?int $id,
        public int $conversationId,
        public int $senderId,
        public int $sequence,
        public string $body,
        public ?string $flaggedReason = null,
        public ?DateTimeImmutable $readAt = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    public function isFlagged(): bool
    {
        return $this->flaggedReason !== null;
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    public function isFrom(int $userId): bool
    {
        return $this->senderId === $userId;
    }
}
