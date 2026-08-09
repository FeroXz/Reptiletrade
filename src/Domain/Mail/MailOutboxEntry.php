<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Mail;

use DateTimeImmutable;

/**
 * Eine Zeile im Postausgang.
 *
 * Die Nachricht steckt unveraendert drin — der Zustelldienst soll genau das
 * verschicken, was der Vorgang formuliert hat, und nicht den Text noch einmal
 * zusammensetzen. Was hier dazukommt, ist die Zustellbuchhaltung.
 */
final readonly class MailOutboxEntry
{
    public function __construct(
        public int $id,
        public MailMessage $message,
        public MailStatus $status,
        public int $attempts,
        public ?string $lastError,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $sentAt = null,
    ) {}
}
