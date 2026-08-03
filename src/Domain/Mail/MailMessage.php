<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Mail;

final readonly class MailMessage
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
        public ?string $toName = null,
    ) {}

    public function recipient(): string
    {
        return $this->toName === null ? $this->to : \sprintf('%s <%s>', $this->toName, $this->to);
    }
}
