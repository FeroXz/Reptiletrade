<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Trust;

/**
 * Prueft Nachrichten- und Anzeigentexte gegen die Wortlisten aus
 * config/trust.php.
 *
 * Der Filter entscheidet nichts allein: Er liefert Treffer samt Begruendung,
 * und der aufrufende Dienst entscheidet, ob die Nachricht zurueckgehalten oder
 * nur der Moderation vorgelegt wird. Ein Wortfilter ist ein Verdacht, kein
 * Urteil — deshalb sperrt nur die Gruppe der Zahlungswege ohne
 * Rueckholmoeglichkeit, alles andere wird bloss markiert.
 */
final readonly class FraudKeywordFilter
{
    /**
     * @param list<KeywordRule> $rules
     */
    public function __construct(
        private array $rules = [],
        private int $flagThreshold = 1,
    ) {}

    public function inspect(string $text): KeywordVerdict
    {
        $matches = [];

        foreach ($this->rules as $rule) {
            $words = $rule->matches($text);

            if ($words !== []) {
                $matches[] = new KeywordMatch($rule->key, $rule->mode, $rule->reason, $words);
            }
        }

        $blocked = false;
        foreach ($matches as $match) {
            if ($match->mode === KeywordMode::Sperre) {
                $blocked = true;

                break;
            }
        }

        return new KeywordVerdict($matches, $blocked, \count($matches) >= $this->flagThreshold);
    }

    /**
     * @return list<string>
     */
    public function ruleKeys(): array
    {
        return array_map(static fn(KeywordRule $rule): string => $rule->key, $this->rules);
    }
}
