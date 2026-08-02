<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal\Rule;

use Reptilienmarkt\Legal\LegalContext;
use Reptilienmarkt\Legal\LegalDecisionDraft;
use Reptilienmarkt\Legal\LegalRule;
use Reptilienmarkt\Legal\NoticeSeverity;

/**
 * Regel 8 — Gewerblichkeit.
 *
 * Ueberschreitet ein Anbieter die konfigurierten Schwellwerte, weist die
 * Plattform auf die Erlaubnispflicht nach § 11 TierSchG hin, verlangt
 * Erlaubnisnummer und Impressum und legt die Anzeige der Moderation vor.
 * Bereits als gewerblich gefuehrte Anbieter werden ohne diese Angaben blockiert.
 *
 * Die Schwellwerte sind eine Betreiber-Heuristik, keine gesetzliche Groesse.
 */
final readonly class CommercialActivityRule implements LegalRule
{
    /**
     * @param list<string> $requiredFields
     */
    public function __construct(
        private int $activeListingThreshold,
        private int $salesPerYearThreshold,
        private array $requiredFields,
        private string $textKey,
    ) {}

    public function key(): string
    {
        return 'gewerblichkeit';
    }

    public function applies(LegalContext $context): bool
    {
        return $context->seller->isCommercial || $this->exceedsThreshold($context);
    }

    public function evaluate(LegalContext $context, LegalDecisionDraft $draft): void
    {
        $seller = $context->seller;

        $draft->requireFields($this->requiredFields, $this->key());
        $draft->notice($this->textKey, $context->jurisdiction(), NoticeSeverity::Warnung);

        $data = [
            'aktive_anzeigen' => $seller->activeListingCount,
            'verkaeufe_12_monate' => $seller->salesLastTwelveMonths,
            'schwelle_anzeigen' => $this->activeListingThreshold,
            'schwelle_verkaeufe' => $this->salesPerYearThreshold,
        ];

        if (!$seller->isCommercial) {
            // Schwelle gerissen, aber noch nicht als gewerblich gefuehrt:
            // die Moderation entscheidet, nicht die Automatik.
            $draft->requireReview($this->key(), 'gewerblichkeitsschwelle_ueberschritten', $data);

            return;
        }

        if (!$seller->hasErlaubnis11Number()) {
            $draft->block($this->key(), 'erlaubnis_11_fehlt', $data);
        }

        if (!$seller->hasImprint) {
            $draft->block($this->key(), 'impressum_fehlt', $data);
        }

        $draft->note($this->key(), 'gewerblicher_anbieter', $data);
    }

    private function exceedsThreshold(LegalContext $context): bool
    {
        return $context->seller->activeListingCount >= $this->activeListingThreshold
            || $context->seller->salesLastTwelveMonths >= $this->salesPerYearThreshold;
    }
}
