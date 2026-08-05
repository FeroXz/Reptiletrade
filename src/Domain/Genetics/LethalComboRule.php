<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Species\Inheritance;

/**
 * Merkmale, deren homozygote Form nicht lebensfaehig ist.
 *
 * Das ist kein eigener Erbgang, sondern eine Eigenschaft des Merkmals: Die
 * Vererbung laeuft wie sonst auch, nur ueberlebt ein Teil der Nachkommen die
 * Entwicklung nicht. Deshalb legt sich diese Regel um eine andere, statt sie zu
 * ersetzen — die Rechnung bleibt dieselbe, die Bewertung kommt hinzu.
 *
 * Die betroffenen Felder werden gekennzeichnet und nicht entfernt. Aus zwei
 * Traegern faellt ein Viertel des Geleges nicht lebensfaehig aus; wer das
 * herausrechnet, zeigt dem Zuechter eine schoenere Verteilung, als das Gelege
 * je hergeben wird.
 */
final readonly class LethalComboRule implements InheritanceRule
{
    public function __construct(private InheritanceRule $inner) {}

    /**
     * Braucht dieser Genort die Bewertung ueberhaupt?
     */
    public static function applies(Locus $locus): bool
    {
        foreach ($locus->alleles() as $allele) {
            if ($allele !== Genotype::WILDTYPE && $locus->isLethalWhenHomozygous($allele)) {
                return true;
            }
        }

        return false;
    }

    public function supports(Inheritance $inheritance): bool
    {
        return $this->inner->supports($inheritance);
    }

    public function cross(Locus $locus, CrossContext $context): Punnett
    {
        $punnett = $this->inner->cross($locus, $context);
        $lethal = [];

        foreach ($punnett->genotypes() as $genotype) {
            $alleles = $punnett->allelesOf($genotype);

            if (\count($alleles) !== 2 || $alleles[0] !== $alleles[1] || $alleles[0] === Genotype::WILDTYPE) {
                continue;
            }

            if ($locus->isLethalWhenHomozygous($alleles[0])) {
                $lethal[] = $genotype;
            }
        }

        return $lethal === [] ? $punnett : $punnett->withLethal($lethal);
    }

    public function warnings(Locus $locus, CrossContext $context): array
    {
        $warnings = $this->inner->warnings($locus, $context);
        $punnett = $this->cross($locus, $context);
        $share = $punnett->lethalShare();

        if ($share <= 0.0) {
            return $warnings;
        }

        $names = [];
        foreach ($punnett->lethalGenotypes() as $genotype) {
            $alleles = $punnett->allelesOf($genotype);
            $morph = $alleles === [] ? null : $locus->morph($alleles[0]);
            if ($morph !== null) {
                $names[$morph->name] = true;
            }
        }

        $warnings[] = new GeneticWarning(
            GeneticWarning::TYPE_LETHAL,
            \sprintf(
                'Bei "%s" ist die homozygote Form nicht lebensfähig. Rechnerisch entfallen %s der Nachkommen '
                . 'aus dieser Verpaarung darauf.',
                implode(', ', array_keys($names)) ?: $locus->label(),
                self::percent($share),
            ),
            WarningSeverity::Fehler,
            $share,
        );

        return $warnings;
    }

    public function express(Locus $locus, array $alleles, Sex $sex): array
    {
        return $this->inner->express($locus, $alleles, $sex);
    }

    private static function percent(float $share): string
    {
        return number_format($share * 100, $share * 100 === floor($share * 100) ? 0 : 1, ',', '.') . ' %';
    }
}
