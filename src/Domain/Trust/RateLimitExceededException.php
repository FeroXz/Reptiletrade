<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Trust;

use RuntimeException;

final class RateLimitExceededException extends RuntimeException
{
    public function __construct(public readonly RateLimitDecision $decision)
    {
        parent::__construct($decision->message());
    }
}
