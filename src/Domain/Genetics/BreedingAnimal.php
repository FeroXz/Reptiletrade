<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\MorphSelection;
use Reptilienmarkt\Domain\Listing\Sex;

/**
 * Ein Elterntier fuer die Simulation.
 *
 * Bewusst kein Verweis auf eine Anzeige: Ein Zuechter rechnet auch mit Tieren
 * durch, die er nicht anbietet — mit dem eigenen Zuchtstamm, mit einem
 * angebotenen Tier eines anderen, mit einem hypothetischen Partner. Die Anzeige
 * ist deshalb eine optionale Herkunftsangabe und keine Voraussetzung.
 */
final readonly class BreedingAnimal
{
    /**
     * @param list<MorphSelection> $morphs
     */
    public function __construct(
        public int $speciesId,
        public Sex $sex = Sex::Unbekannt,
        public array $morphs = [],
        public ?int $listingId = null,
        public string $label = '',
    ) {}

    /**
     * @param list<MorphSelection> $morphs
     */
    public static function fromListing(int $listingId, int $speciesId, Sex $sex, array $morphs, string $label = ''): self
    {
        return new self($speciesId, $sex, $morphs, $listingId, $label);
    }

    public function withSex(Sex $sex): self
    {
        return new self($this->speciesId, $sex, $this->morphs, $this->listingId, $this->label);
    }

    /**
     * Traegt das Tier Angaben, die nur mit einer Wahrscheinlichkeit stimmen
     * ("66 % poss. het")? Dann ist auch das Ergebnis eine Erwartung ueber
     * mehrere moegliche Elterngenotypen.
     */
    public function hasUncertainTraits(): bool
    {
        foreach ($this->morphs as $selection) {
            if (!$selection->isVisual() && $selection->zygosity->probability() < 1.0) {
                return true;
            }
        }

        return false;
    }
}
