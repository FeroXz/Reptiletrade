<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\Sex;

/**
 * Alles, was eine Vererbungsregel ueber eine Verpaarung wissen muss.
 *
 * Die Geschlechter stehen mit im Zusammenhang, weil sie bei
 * geschlechtsgebundenen Merkmalen zur Rechnung gehoeren und nicht zur
 * Darstellung: Aus einem Z-gebundenen Vater wird bei Toechtern und Soehnen
 * Verschiedenes. Fuer autosomale Merkmale bleibt der Zusammenhang folgenlos.
 */
final readonly class CrossContext
{
    public function __construct(
        public Genotype $first,
        public Sex $firstSex,
        public Genotype $second,
        public Sex $secondSex,
        public Sex $offspringSex,
        public SexSystem $sexSystem = SexSystem::Zw,
    ) {}

    public function withOffspringSex(Sex $sex): self
    {
        return new self($this->first, $this->firstSex, $this->second, $this->secondSex, $sex, $this->sexSystem);
    }

    public function withGenotypes(Genotype $first, Genotype $second): self
    {
        return new self($first, $this->firstSex, $second, $this->secondSex, $this->offspringSex, $this->sexSystem);
    }

    /**
     * Sind die Geschlechter der Eltern bekannt und verschieden? Nur dann laesst
     * sich ein geschlechtsgebundenes Merkmal ueberhaupt zuordnen.
     */
    public function hasDistinctSexes(): bool
    {
        return $this->firstSex !== Sex::Unbekannt
            && $this->secondSex !== Sex::Unbekannt
            && $this->firstSex !== $this->secondSex;
    }

    /**
     * Der Genotyp des Elterntiers mit zwei Geschlechtschromosomen (ZZ
     * beziehungsweise XX).
     */
    public function homogameticParent(): ?Genotype
    {
        $hemizygous = $this->sexSystem->hemizygousSex();

        if ($hemizygous === null || !$this->hasDistinctSexes()) {
            return null;
        }

        return $this->firstSex === $hemizygous ? $this->second : $this->first;
    }

    /**
     * Der Genotyp des Elterntiers mit nur einem allel-tragenden
     * Geschlechtschromosom (ZW beziehungsweise XY).
     */
    public function hemizygousParent(): ?Genotype
    {
        $hemizygous = $this->sexSystem->hemizygousSex();

        if ($hemizygous === null || !$this->hasDistinctSexes()) {
            return null;
        }

        return $this->firstSex === $hemizygous ? $this->first : $this->second;
    }

    public function offspringIsHemizygous(): bool
    {
        return $this->sexSystem->isHemizygous($this->offspringSex);
    }
}
