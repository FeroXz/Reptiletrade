<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\MorphSelection;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Listing\Zygosity;
use Reptilienmarkt\Domain\Species\Inheritance;

/**
 * Geschlechtsgebundene Merkmale — die Anlage liegt auf einem
 * Geschlechtschromosom.
 *
 * Bei Reptilien mit ZW-System (Weibchen ZW, Maennchen ZZ) heisst das:
 *
 * - Das Weibchen hat nur ein Z und damit nur ein Allel. Es zeigt das Merkmal
 *   schon mit einer einzigen Anlage — "het" gibt es bei ihm nicht.
 * - Das Maennchen hat zwei Z und kann Traeger sein, ohne etwas zu zeigen.
 * - Toechter bekommen ihr Z immer vom Vater, Soehne je eines von beiden.
 *
 * Daraus folgt die Regel, die in keiner autosomalen Rechnung vorkommt: Aus
 * einem sichtbaren Vater fallen ausnahmslos sichtbare Toechter, aus einer
 * sichtbaren Mutter dagegen Soehne, die nur Traeger sind. Wer das mit einem
 * gewoehnlichen Punnett-Quadrat rechnet, bekommt fuer beide Richtungen
 * dasselbe Ergebnis — und liegt in einer davon falsch.
 *
 * Die Allele werden wie rezessive Anlagen behandelt: Beim homogametischen
 * Geschlecht verdeckt ein Wildtyp-Allel die Anlage. Das ist der Fall, der in
 * der Zuchtpraxis vorkommt.
 */
final readonly class SexLinkedRule implements InheritanceRule
{
    public function supports(Inheritance $inheritance): bool
    {
        return $inheritance === Inheritance::SexLinked;
    }

    public function cross(Locus $locus, CrossContext $context): Punnett
    {
        $homogametic = $context->homogameticParent();
        $hemizygous = $context->hemizygousParent();

        // Ohne bekannte, verschiedene Geschlechter bleibt nur die autosomale
        // Naeherung. Sie steht hier, damit eine Simulation ohne
        // Geschlechtsangabe ein Ergebnis liefert — die Warnung dazu sagt,
        // woran es fehlt.
        if ($homogametic === null || $hemizygous === null) {
            return Punnett::fromGametes(
                $locus->id,
                $locus->label(),
                $context->first->at($locus->id),
                $context->second->at($locus->id),
            );
        }

        $paired = $homogametic->at($locus->id);
        $single = $hemizygous->at($locus->id, hemizygous: true)[0];

        if ($context->offspringIsHemizygous()) {
            // Ein Allel vom homogametischen Elternteil, dazu das leere
            // Geschlechtschromosom (W beziehungsweise Y) vom anderen.
            $cells = [];
            foreach ($paired as $allele) {
                $cells[] = [[$allele]];
            }

            return new Punnett($locus->id, $locus->label(), $paired, [$this->emptyChromosome($context)], $cells);
        }

        return Punnett::fromGametes($locus->id, $locus->label(), $paired, [$single]);
    }

    public function warnings(Locus $locus, CrossContext $context): array
    {
        if (!$context->sexSystem->supportsSexLinkage()) {
            return [
                new GeneticWarning(
                    GeneticWarning::TYPE_SEX,
                    \sprintf(
                        'Für diese Art ist kein Geschlechtschromosomen-System hinterlegt. "%s" ist als '
                        . 'geschlechtsgebunden geführt — die Verteilung wird deshalb wie bei einem gewöhnlichen '
                        . 'Genort gerechnet.',
                        $locus->label(),
                    ),
                    WarningSeverity::Warnung,
                ),
            ];
        }

        if (!$context->hasDistinctSexes()) {
            return [
                new GeneticWarning(
                    GeneticWarning::TYPE_SEX,
                    \sprintf(
                        '"%s" wird geschlechtsgebunden vererbt. Ohne Geschlechtsangabe zu beiden Elterntieren '
                        . 'lässt sich nicht trennen, was auf Söhne und was auf Töchter entfällt.',
                        $locus->label(),
                    ),
                    WarningSeverity::Warnung,
                ),
            ];
        }

        return [
            new GeneticWarning(
                GeneticWarning::TYPE_SEX,
                \sprintf(
                    '"%s" liegt auf dem Geschlechtschromosom (%s). Söhne und Töchter fallen deshalb '
                    . 'unterschiedlich aus — die Aufstellung nach Geschlecht steht im Bericht.',
                    $locus->label(),
                    $context->sexSystem->label(),
                ),
                WarningSeverity::Hinweis,
            ),
        ];
    }

    public function express(Locus $locus, array $alleles, Sex $sex): array
    {
        $mutants = [];
        foreach ($alleles as $allele) {
            if ($allele !== Genotype::WILDTYPE && !\in_array($allele, $mutants, true)) {
                $mutants[] = $allele;
            }
        }

        if ($mutants === []) {
            return [];
        }

        // Hemizygot: ein Allel, kein zweites, das etwas verdecken koennte.
        if (\count($alleles) === 1) {
            $morph = $locus->morph($mutants[0]);

            return $morph === null ? [] : [new MorphSelection($morph, Zygosity::Visual)];
        }

        if (\count($mutants) === 1 && $alleles[0] !== $alleles[1]) {
            $morph = $locus->morph($mutants[0]);

            return $morph === null ? [] : [new MorphSelection($morph, Zygosity::Het)];
        }

        $expression = [];
        foreach ($mutants as $allele) {
            $morph = $locus->morph($allele);
            if ($morph !== null) {
                $expression[] = new MorphSelection($morph, Zygosity::Visual);
            }
        }

        return $expression;
    }

    /**
     * Bezeichnung des allelfreien Geschlechtschromosoms fuer die Darstellung.
     */
    private function emptyChromosome(CrossContext $context): string
    {
        return $context->sexSystem === SexSystem::Xy ? 'Y' : 'W';
    }
}
