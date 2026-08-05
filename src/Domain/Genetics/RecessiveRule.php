<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Species\Inheritance;

/**
 * Rezessive Merkmale: sichtbar erst, wenn beide Allele die Anlage tragen.
 *
 *     aa x ++  ->  100 % a+  (alle het, keiner sichtbar)
 *     a+ x a+  ->   25 % aa,  50 % a+,  25 % ++
 *     aa x a+  ->   50 % aa,  50 % a+
 *
 * Der haeufigste Irrtum im Marktplatz steht in der zweiten Zeile: Aus zwei
 * unauffaelligen Elterntieren fallen sichtbare Nachkommen — und drei Viertel
 * des Geleges sehen wildtypisch aus, obwohl zwei Drittel davon Traeger sind.
 * Genau daher kommt die Angabe "66 % poss. het".
 *
 * Mischerbige an einem allelischen Genort (Zero/Witblits bei der Bartagame)
 * sind keine Traeger, sondern zeigen beide Merkmale zusammen. Ein Modell, das
 * je Merkmal statt je Genort rechnet, uebersieht das.
 */
final readonly class RecessiveRule extends AutosomalRule
{
    public function supports(Inheritance $inheritance): bool
    {
        return $inheritance === Inheritance::Recessive;
    }

    public function express(Locus $locus, array $alleles, Sex $sex): array
    {
        $mutants = $this->mutantAlleles($alleles);

        if ($mutants === []) {
            return [];
        }

        // Ein einzelnes Anlage-Allel neben dem Wildtyp: Traeger.
        if (\count($alleles) === 2 && \count($mutants) === 1 && !$this->isHomozygous($alleles)) {
            $morph = $locus->morph($mutants[0]);

            return $morph === null ? [] : [$this->het($morph)];
        }

        // Reinerbig oder mischerbig aus zwei Anlagen desselben Genorts:
        // beides ist sichtbar.
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
        if (!$locus->isMultiAllelic()) {
            return [];
        }

        $firstMutants = $this->mutantAlleles($context->first->at($locus->id));
        $secondMutants = $this->mutantAlleles($context->second->at($locus->id));

        // Nur warnen, wenn tatsaechlich zwei verschiedene Anlagen desselben
        // Genorts zusammenkommen koennen.
        if (array_diff($firstMutants, $secondMutants) === [] && array_diff($secondMutants, $firstMutants) === []) {
            return [];
        }

        return [
            new GeneticWarning(
                GeneticWarning::TYPE_ALLELIC,
                \sprintf(
                    'Die Merkmale am Genort "%s" sind allelisch. Nachkommen mit je einer Anlage beider Merkmale '
                    . 'zeigen eine Mischform und sind keine Träger — sie lassen sich nicht als "het" führen.',
                    $locus->label(),
                ),
                WarningSeverity::Hinweis,
            ),
        ];
    }
}
