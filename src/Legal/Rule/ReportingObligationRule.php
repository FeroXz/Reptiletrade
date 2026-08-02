<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal\Rule;

use Reptilienmarkt\Legal\LegalContext;
use Reptilienmarkt\Legal\LegalDecisionDraft;
use Reptilienmarkt\Legal\LegalRule;
use Reptilienmarkt\Legal\NoticeSeverity;
use Reptilienmarkt\Support\Clock;

/**
 * Regel 3 — meldepflichtige Arten (§ 7 BArtSchV).
 *
 * Der Anbieter muss bestaetigen, dass die Meldung bei der zustaendigen Behoerde
 * erfolgt ist, und das Datum angeben. Fehlt die Bestaetigung oder liegt das
 * Datum in der Zukunft, wird blockiert.
 */
final readonly class ReportingObligationRule implements LegalRule
{
    /**
     * @param list<string> $requiredFields
     */
    public function __construct(
        private Clock $clock,
        private array $requiredFields,
        private string $confirmationField,
        private string $dateField,
        private string $textKey,
    ) {}

    public function key(): string
    {
        return 'meldepflicht';
    }

    public function applies(LegalContext $context): bool
    {
        return $context->describesAnimalOnOffer() && $context->species->meldepflicht;
    }

    public function evaluate(LegalContext $context, LegalDecisionDraft $draft): void
    {
        $draft->requireFields($this->requiredFields, $this->key());
        $draft->notice($this->textKey, $context->jurisdiction(), NoticeSeverity::Warnung);

        $art = $context->species->scientificName;

        if (!$context->confirmationBool($this->confirmationField)) {
            $draft->block($this->key(), 'meldung_nicht_bestaetigt', ['art' => $art]);

            return;
        }

        $date = $context->confirmationDate($this->dateField);

        if ($date === null) {
            $draft->block($this->key(), 'meldedatum_fehlt', ['art' => $art]);

            return;
        }

        if ($date > $this->clock->now()) {
            $draft->block($this->key(), 'meldedatum_in_zukunft', [
                'art' => $art,
                'datum' => $date->format('Y-m-d'),
            ]);

            return;
        }

        $draft->note($this->key(), 'meldung_bestaetigt', ['art' => $art, 'datum' => $date->format('Y-m-d')]);
    }
}
