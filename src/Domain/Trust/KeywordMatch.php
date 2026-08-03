<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Trust;

final readonly class KeywordMatch
{
    /**
     * @param list<string> $words
     */
    public function __construct(
        public string $ruleKey,
        public KeywordMode $mode,
        public string $reason,
        public array $words,
    ) {}
}
