<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal\Rule;

use Reptilienmarkt\Domain\Listing\LegalDocType;
use Reptilienmarkt\Domain\Species\BnatschgStatus;
use Reptilienmarkt\Domain\Species\EuAnnex;
use Reptilienmarkt\Legal\LegalContext;
use Reptilienmarkt\Legal\LegalDecisionDraft;
use Reptilienmarkt\Legal\LegalRule;
use Reptilienmarkt\Legal\NoticeSeverity;

/**
 * Regel 1 — Anhang A und streng geschuetzte Arten.
 *
 * Vermarktung nur mit EU-Vermarktungsbescheinigung samt Referenznummer. Die
 * Anzeige geht in jedem Fall in den Status "pruefung" und braucht eine
 * Admin-Freigabe; ohne Referenznummer wird sie blockiert.
 */
final readonly class AnnexACertificateRule implements LegalRule
{
    /**
     * @param list<EuAnnex>        $annexes
     * @param list<BnatschgStatus> $bnatschgStatuses
     * @param list<string>         $requiredFields
     */
    public function __construct(
        private array $annexes,
        private array $bnatschgStatuses,
        private array $requiredFields,
        private string $textKey,
    ) {}

    public function key(): string
    {
        return 'anhang_a';
    }

    public function applies(LegalContext $context): bool
    {
        if (!$context->describesAnimalOnOffer()) {
            return false;
        }

        $species = $context->species;

        if ($species->euAnnex !== null && \in_array($species->euAnnex, $this->annexes, true)) {
            return true;
        }

        return \in_array($species->bnatschgStatus, $this->bnatschgStatuses, true);
    }

    public function evaluate(LegalContext $context, LegalDecisionDraft $draft): void
    {
        $draft->requireFields($this->requiredFields, $this->key());
        $draft->notice($this->textKey, $context->jurisdiction(), NoticeSeverity::Warnung);

        $draft->requireReview($this->key(), 'freigabe_noetig', [
            'eu_annex' => $context->species->euAnnex?->value,
            'bnatschg_status' => $context->species->bnatschgStatus->value,
            'art' => $context->species->scientificName,
        ]);

        $document = $context->document(LegalDocType::EuBescheinigung);

        if ($document === null || !$document->hasReferenceNumber()) {
            $draft->block($this->key(), 'eu_bescheinigung_fehlt', [
                'art' => $context->species->scientificName,
                'dokument_vorhanden' => $document !== null,
            ]);
        }
    }
}
