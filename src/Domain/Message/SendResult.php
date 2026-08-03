<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Message;

use Reptilienmarkt\Domain\Trust\KeywordVerdict;

final readonly class SendResult
{
    public function __construct(
        public bool $sent,
        public ?int $messageId,
        public KeywordVerdict $verdict,
        public string $message,
    ) {}
}
