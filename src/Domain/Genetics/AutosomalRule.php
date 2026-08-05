<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\MorphSelection;
use Reptilienmarkt\Domain\Listing\Zygosity;
use Reptilienmarkt\Domain\Species\Morph;

/**
 * Gemeinsamer Unterbau aller Regeln fuer Merkmale auf gewoehnlichen
 * Chromosomen.
 *
 * Die Kreuzung selbst ist bei dominant, rezessiv und unvollstaendig dominant
 * dieselbe Rechnung — jedes Elterntier gibt eines seiner beiden Allele weiter.
 * Die Erbgaenge unterscheiden sich erst bei der Frage, was man dem Ergebnis
 * ansieht. Genau deshalb steht hier nur cross(), und express() bleibt den
 * einzelnen Regeln ueberlassen.
 */
abstract readonly class AutosomalRule implements InheritanceRule
{
    public function cross(Locus $locus, CrossContext $context): Punnett
    {
        return Punnett::fromGametes(
            $locus->id,
            $locus->label(),
            $context->first->at($locus->id),
            $context->second->at($locus->id),
        );
    }

    public function warnings(Locus $locus, CrossContext $context): array
    {
        return [];
    }

    /**
     * Die nicht wildtypischen Allele eines Genotyps, jedes nur einmal.
     *
     * @param list<string> $alleles
     *
     * @return list<string>
     */
    final protected function mutantAlleles(array $alleles): array
    {
        $mutants = [];

        foreach ($alleles as $allele) {
            if ($allele !== Genotype::WILDTYPE && !\in_array($allele, $mutants, true)) {
                $mutants[] = $allele;
            }
        }

        return $mutants;
    }

    /**
     * @param list<string> $alleles
     */
    final protected function isHomozygous(array $alleles): bool
    {
        return \count($alleles) === 2 && $alleles[0] === $alleles[1];
    }

    final protected function visual(Morph $morph): MorphSelection
    {
        return new MorphSelection($morph, Zygosity::Visual);
    }

    final protected function het(Morph $morph): MorphSelection
    {
        return new MorphSelection($morph, Zygosity::Het);
    }
}
