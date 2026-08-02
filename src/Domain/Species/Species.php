<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Species;

/**
 * Ein Eintrag im Artenstamm. Traegt sowohl Haltungs- als auch Rechtsmerkmale;
 * die Rechts-Engine (Phase 2) liest ausschliesslich aus diesen Feldern.
 */
final readonly class Species
{
    public function __construct(
        public ?int $id,
        public string $scientificName,
        public string $commonNameDe,
        public string $slug,
        public ?string $family = null,
        public ?string $orderTaxon = null,
        public ?CitesAppendix $citesAppendix = null,
        public ?EuAnnex $euAnnex = null,
        public BnatschgStatus $bnatschgStatus = BnatschgStatus::NichtGeschuetzt,
        public bool $meldepflicht = false,
        public bool $dokuPflicht = false,
        public bool $gefahrtier = false,
        public ?CareLevel $careLevel = null,
        public ?int $adultSizeCm = null,
        public ?int $lifespanYears = null,
        public ?int $minAbgabeAlterWochen = null,
        public ?int $minAbgabeGewichtG = null,
    ) {}

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->scientificName,
            $this->commonNameDe,
            $this->slug,
            $this->family,
            $this->orderTaxon,
            $this->citesAppendix,
            $this->euAnnex,
            $this->bnatschgStatus,
            $this->meldepflicht,
            $this->dokuPflicht,
            $this->gefahrtier,
            $this->careLevel,
            $this->adultSizeCm,
            $this->lifespanYears,
            $this->minAbgabeAlterWochen,
            $this->minAbgabeGewichtG,
        );
    }

    /**
     * Gattung aus dem wissenschaftlichen Namen, z. B. "Testudo" aus "Testudo hermanni".
     */
    public function genus(): string
    {
        $parts = explode(' ', $this->scientificName, 2);

        return $parts[0];
    }
}
