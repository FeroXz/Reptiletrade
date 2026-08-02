<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal\Rule;

use Reptilienmarkt\Domain\Listing\LegalDocType;
use Reptilienmarkt\Domain\Species\EuAnnex;
use Reptilienmarkt\Legal\LegalContext;
use Reptilienmarkt\Legal\LegalDecisionDraft;
use Reptilienmarkt\Legal\LegalRule;
use Reptilienmarkt\Legal\NoticeSeverity;

/**
 * Regel 2 — Anhang B.
 *
 * Herkunftsnachweis ist Pflichtfeld, dazu ein Hinweis auf die Nachweispflicht.
 * Anhang-A-Arten sind ausgenommen: Dort tritt die Vermarktungsbescheinigung
 * aus Regel 1 an diese Stelle.
 */
final readonly class AnnexBProvenanceRule implements LegalRule
{
    /**
     * @param list<EuAnnex> $annexes
     * @param list<EuAnnex> $excludedAnnexes
     * @param list<string>  $requiredFields
     */
    public function __construct(
        private array $annexes,
        private array $excludedAnnexes,
        private bool $includeDokuPflicht,
        private bool $blockWhenMissing,
        private array $requiredFields,
        private string $textKey,
    ) {}

    public function key(): string
    {
        return 'anhang_b';
    }

    public function applies(LegalContext $context): bool
    {
        if (!$context->describesAnimalOnOffer()) {
            return false;
        }

        $annex = $context->species->euAnnex;

        if ($annex !== null && \in_array($annex, $this->excludedAnnexes, true)) {
            return false;
        }

        if ($annex !== null && \in_array($annex, $this->annexes, true)) {
            return true;
        }

        return $this->includeDokuPflicht && $context->species->dokuPflicht;
    }

    public function evaluate(LegalContext $context, LegalDecisionDraft $draft): void
    {
        $draft->requireFields($this->requiredFields, $this->key());
        $draft->notice($this->textKey, $context->jurisdiction(), NoticeSeverity::Info);

        if ($context->hasDocument(LegalDocType::Herkunftsnachweis)) {
            return;
        }

        $data = [
            'art' => $context->species->scientificName,
            'eu_annex' => $context->species->euAnnex?->value,
        ];

        if ($this->blockWhenMissing) {
            $draft->block($this->key(), 'herkunftsnachweis_fehlt', $data);

            return;
        }

        $draft->note($this->key(), 'herkunftsnachweis_fehlt', $data);
    }
}
