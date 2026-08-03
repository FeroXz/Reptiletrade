<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

use Reptilienmarkt\Domain\Species\Morph;

/**
 * Ein Merkmal am konkreten Tier: der Katalogeintrag plus seine Auspraegung.
 */
final readonly class MorphSelection
{
    public function __construct(
        public Morph $morph,
        public Zygosity $zygosity = Zygosity::Visual,
    ) {}

    /**
     * Handelsueblicher Kurzname. In der Szene setzt sich fast immer die
     * kuerzeste Form durch — "Hypo" statt "Hypomelanistic".
     */
    public function shortLabel(): string
    {
        $shortest = $this->morph->name;

        foreach ($this->morph->aliases as $alias) {
            if (mb_strlen($alias) < mb_strlen($shortest)) {
                $shortest = $alias;
            }
        }

        return $shortest;
    }

    public function isVisual(): bool
    {
        return $this->zygosity->isVisual();
    }

    /**
     * Eine het-Angabe ergibt nur bei rezessiven Merkmalen einen Sinn.
     */
    public function hasInconsistentZygosity(): bool
    {
        return !$this->isVisual() && !$this->morph->inheritance->allowsHeterozygous();
    }
}
