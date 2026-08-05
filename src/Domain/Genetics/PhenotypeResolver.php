<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\Sex;

/**
 * Genotyp zu Phaenotyp: Was sieht man dem Tier an, was traegt es nur?
 *
 * Die Uebersetzung gehoert nicht in den Genotyp selbst, denn sie haengt an
 * Dingen, die er nicht kennt: am Erbgang des Genorts, an hinterlegten
 * Superformen und — bei geschlechtsgebundenen Merkmalen — am Geschlecht des
 * Tieres.
 */
final readonly class PhenotypeResolver
{
    public function __construct(
        private LocusMap $map,
        private RuleRegistry $rules,
    ) {}

    public function resolve(Genotype $genotype, Sex $sex = Sex::Unbekannt): Phenotype
    {
        $selections = [];

        foreach ($genotype->loci() as $locusId) {
            $locus = $this->map->get($locusId);

            if ($locus === null) {
                continue;
            }

            foreach ($this->rules->ruleFor($locus)->express($locus, $genotype->at($locusId), $sex) as $selection) {
                $selections[] = $selection;
            }
        }

        return new Phenotype($selections);
    }
}
