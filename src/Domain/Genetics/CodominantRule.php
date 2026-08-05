<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Species\Inheritance;

/**
 * Unvollstaendig dominante Merkmale — in der Zuchtszene meist "kodominant"
 * genannt.
 *
 *     A+ x A+  ->  25 % AA (Superform), 50 % A+ (Basisform), 25 % ++
 *
 * Der Unterschied zum dominanten Erbgang ist die homozygote Form: Sie sieht
 * anders aus als die heterozygote und traegt einen eigenen Namen (Silkback zur
 * Leatherback). Welche Merkmale so zusammengehoeren, steht in
 * config/genetik.php; ohne hinterlegte Superform bleibt die homozygote Form
 * unter dem Namen der Basisform stehen, statt eine Bezeichnung zu erfinden.
 *
 * Ob die Superform lebensfaehig oder tierschutzrelevant ist, entscheidet nicht
 * dieser Erbgang, sondern das Merkmal — siehe LethalComboRule und die
 * Tierschutzhinweise in config/genetik.php.
 */
final readonly class CodominantRule extends AutosomalRule
{
    public function supports(Inheritance $inheritance): bool
    {
        return $inheritance === Inheritance::IncompleteDominant;
    }

    public function express(Locus $locus, array $alleles, Sex $sex): array
    {
        $mutants = $this->mutantAlleles($alleles);

        if ($mutants === []) {
            return [];
        }

        if ($this->isHomozygous($alleles)) {
            $morph = $locus->morph($mutants[0]);
            if ($morph === null) {
                return [];
            }

            return [$this->visual($locus->superForm($mutants[0]) ?? $morph)];
        }

        // Ein Allel je Merkmal — die Basisform, bei zwei verschiedenen Anlagen
        // beide nebeneinander.
        $expression = [];
        foreach ($mutants as $allele) {
            $morph = $locus->morph($allele);
            if ($morph !== null) {
                $expression[] = $this->visual($morph);
            }
        }

        return $expression;
    }

    public function warnings(Locus $locus, CrossContext $context): array
    {
        $warnings = [];

        foreach ($this->mutantAlleles($context->first->at($locus->id)) as $allele) {
            if (!$context->second->carries($locus->id, $allele)) {
                continue;
            }

            $super = $locus->superForm($allele);
            if ($super === null) {
                continue;
            }

            $warnings[] = new GeneticWarning(
                GeneticWarning::TYPE_ALLELIC,
                \sprintf(
                    'Beide Elterntiere tragen die Anlage "%s". Ein Teil der Nachzucht bekommt sie doppelt und '
                    . 'wird zur Superform "%s".',
                    $locus->morph($allele)?->name ?? $allele,
                    $super->name,
                ),
                WarningSeverity::Hinweis,
            );
        }

        return $warnings;
    }
}
