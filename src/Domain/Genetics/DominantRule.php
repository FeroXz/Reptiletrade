<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Species\Inheritance;

/**
 * Dominante Merkmale: Ein Allel genuegt, damit man sie sieht.
 *
 *     AA x ++  ->  100 % A+  (alle sichtbar)
 *     A+ x ++  ->   50 % A+,  50 % ++
 *     A+ x A+  ->   25 % AA,  50 % A+,  25 % ++
 *
 * Homozygote sehen aus wie Heterozygote — anders als bei unvollstaendig
 * dominanten Merkmalen gibt es keine Superform. Wer wissen will, ob ein
 * sichtbares Tier reinerbig ist, muss es verpaaren; darum steht der Unterschied
 * in der Genotyp-Kurzform des Berichts und nicht im Morph-String.
 */
final readonly class DominantRule extends AutosomalRule
{
    public function supports(Inheritance $inheritance): bool
    {
        return $inheritance === Inheritance::Dominant;
    }

    public function express(Locus $locus, array $alleles, Sex $sex): array
    {
        $expression = [];

        foreach ($this->mutantAlleles($alleles) as $allele) {
            $morph = $locus->morph($allele);
            if ($morph === null) {
                continue;
            }

            // Auch dominante Merkmale koennen eine benannte homozygote Form
            // haben (im Katalog als Superform hinterlegt).
            $super = $this->isHomozygous($alleles) ? $locus->superForm($allele) : null;

            $expression[] = $this->visual($super ?? $morph);
        }

        return $expression;
    }
}
