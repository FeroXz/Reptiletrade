<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

/**
 * Nachvollziehbarer Grund fuer eine Entscheidung der Rechts-Engine.
 * Landet unveraendert im audit_log.
 */
final readonly class LegalReason
{
    /**
     * @param array<string, scalar|null> $data
     */
    public function __construct(
        public string $rule,
        public string $code,
        public LegalEffect $effect,
        public array $data = [],
    ) {}

    /**
     * @return array{rule: string, code: string, effect: string, data: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'code' => $this->code,
            'effect' => $this->effect->value,
            'data' => $this->data,
        ];
    }
}
