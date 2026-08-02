<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

/**
 * Einstiegspunkt der Rechts-Engine.
 *
 * Aufzurufen bei jedem Listing-Submit und bei jeder Statusaenderung auf "aktiv".
 * Die Regeln selbst stehen in config/legal_rules.php, nicht hier.
 *
 * KEINE RECHTSBERATUNG: Die Engine setzt das vom Betreiber konfigurierte
 * Regelwerk um. Richtigkeit und Aktualitaet des Regelwerks verantwortet der
 * Betreiber (siehe Disclaimer).
 */
final readonly class LegalGuard
{
    /**
     * @param list<LegalRule> $rules
     */
    public function __construct(
        private array $rules,
        private LegalTextResolver $texts,
    ) {}

    public function evaluate(LegalContext $context): LegalDecision
    {
        $draft = new LegalDecisionDraft($this->texts);

        foreach ($this->rules as $rule) {
            if (!$rule->applies($context)) {
                continue;
            }

            $rule->evaluate($context, $draft);
        }

        return $draft->build();
    }

    /**
     * Feldnamen, die das mehrstufige Formular abfragen muss. Phase 4 baut den
     * Schritt "Rechtsnachweise" dynamisch aus dieser Liste.
     *
     * @return list<string>
     */
    public function requiredFields(LegalContext $context): array
    {
        return $this->evaluate($context)->requiredFields;
    }

    /**
     * Regelschluessel in Auswertungsreihenfolge — fuer Admin und Tests.
     *
     * @return list<string>
     */
    public function ruleKeys(): array
    {
        return array_map(static fn(LegalRule $rule): string => $rule->key(), $this->rules);
    }
}
