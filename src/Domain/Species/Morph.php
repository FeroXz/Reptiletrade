<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Species;

/**
 * Ein Merkmal ("Morph") innerhalb einer Art.
 */
final readonly class Morph
{
    /**
     * @param list<string> $aliases Handelsuebliche Zweitnamen, fliessen in den Volltextindex
     */
    public function __construct(
        public ?int $id,
        public int $speciesId,
        public string $name,
        public Inheritance $inheritance,
        public array $aliases = [],
        public ?string $alleleGroup = null,
        public bool $isLethalCombo = false,
        public ?string $description = null,
    ) {}

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->speciesId,
            $this->name,
            $this->inheritance,
            $this->aliases,
            $this->alleleGroup,
            $this->isLethalCombo,
            $this->description,
        );
    }

    /**
     * Merkmale derselben Allelgruppe besetzen denselben Genort und koennen
     * beim Kombinieren zu Super-Formen oder Letalkombinationen fuehren.
     */
    public function sharesLocusWith(self $other): bool
    {
        return $this->alleleGroup !== null && $this->alleleGroup === $other->alleleGroup;
    }
}
