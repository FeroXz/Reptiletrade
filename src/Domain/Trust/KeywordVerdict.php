<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Trust;

final readonly class KeywordVerdict
{
    /**
     * @param list<KeywordMatch> $matches
     */
    public function __construct(
        public array $matches,
        public bool $blocked,
        public bool $flagged,
    ) {}

    public function isClean(): bool
    {
        return $this->matches === [];
    }

    /**
     * Kurzform fuer messages.flagged_reason.
     */
    public function reasonSummary(): ?string
    {
        if ($this->matches === []) {
            return null;
        }

        return implode('; ', array_map(
            static fn(KeywordMatch $match): string => $match->ruleKey . ': ' . implode(', ', $match->words),
            $this->matches,
        ));
    }

    /**
     * Was der Absender zu sehen bekommt, wenn die Nachricht nicht rausgeht.
     * Die konkreten Treffer stehen bewusst nicht darin — sonst liesse sich die
     * Wortliste durch Ausprobieren rekonstruieren.
     */
    public function senderMessage(): string
    {
        foreach ($this->matches as $match) {
            if ($match->mode === KeywordMode::Sperre) {
                return 'Diese Nachricht wurde nicht gesendet. Sie enthält einen Zahlungsweg, '
                    . 'der auf dieser Plattform nicht zulässig ist. Bezahlt wird bei der Übergabe.';
            }
        }

        return 'Die Nachricht wurde gesendet.';
    }

    /**
     * Fuer den Audit-Trail.
     *
     * @return array<string, mixed>
     */
    public function auditPayload(): array
    {
        return [
            'regeln' => array_map(static fn(KeywordMatch $match): string => $match->ruleKey, $this->matches),
            'gesperrt' => $this->blocked,
            'markiert' => $this->flagged,
        ];
    }
}
