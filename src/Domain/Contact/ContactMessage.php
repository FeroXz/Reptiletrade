<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Contact;

use DateTimeImmutable;

final readonly class ContactMessage
{
    public function __construct(
        public ?int $id,
        public ?int $userId,
        public string $name,
        public string $email,
        public ContactTopic $topic,
        public string $subject,
        public string $body,
        public bool $handled = false,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $handledAt = null,
        public ?string $handledNote = null,
    ) {}
}
