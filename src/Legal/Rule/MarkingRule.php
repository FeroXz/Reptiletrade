<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal\Rule;

use Reptilienmarkt\Legal\LegalContext;
use Reptilienmarkt\Legal\LegalDecisionDraft;
use Reptilienmarkt\Legal\LegalRule;
use Reptilienmarkt\Legal\NoticeSeverity;

/**
 * Regel 4 — Kennzeichnung bei Landschildkroeten.
 *
 * Zusaetzlich zu den Nachweisen aus Regel 1 und 2 wird die Art der Kennzeichnung
 * abgefragt (Transponder oder Fotodokumentation). Beim Transponder ist die
 * Chipnummer anzugeben.
 */
final readonly class MarkingRule implements LegalRule
{
    /**
     * @param list<string> $genera             Gattungen, fuer die die Regel gilt
     * @param list<string> $allowedMethods
     * @param list<string> $methodsNeedingCode Methoden, die zusaetzlich eine Nummer verlangen
     */
    public function __construct(
        private array $genera,
        private array $allowedMethods,
        private array $methodsNeedingCode,
        private string $methodField,
        private string $codeField,
        private string $textKey,
    ) {}

    public function key(): string
    {
        return 'kennzeichnung';
    }

    public function applies(LegalContext $context): bool
    {
        return $context->describesAnimalOnOffer()
            && \in_array($context->species->genus(), $this->genera, true);
    }

    public function evaluate(LegalContext $context, LegalDecisionDraft $draft): void
    {
        $draft->requireField($this->methodField, $this->key());
        $draft->notice($this->textKey, $context->jurisdiction(), NoticeSeverity::Warnung);

        $art = $context->species->scientificName;
        $method = $context->confirmationString($this->methodField);

        if ($method === null) {
            $draft->block($this->key(), 'kennzeichnung_fehlt', ['art' => $art]);

            return;
        }

        if (!\in_array($method, $this->allowedMethods, true)) {
            $draft->block($this->key(), 'kennzeichnung_unbekannt', ['art' => $art, 'methode' => $method]);

            return;
        }

        if (!\in_array($method, $this->methodsNeedingCode, true)) {
            $draft->note($this->key(), 'kennzeichnung_angegeben', ['art' => $art, 'methode' => $method]);

            return;
        }

        $draft->requireField($this->codeField, $this->key());

        if ($context->confirmationString($this->codeField) === null) {
            $draft->block($this->key(), 'kennzeichnungsnummer_fehlt', ['art' => $art, 'methode' => $method]);

            return;
        }

        $draft->note($this->key(), 'kennzeichnung_angegeben', ['art' => $art, 'methode' => $method]);
    }
}
