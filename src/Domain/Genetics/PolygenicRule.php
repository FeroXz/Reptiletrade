<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Species\Inheritance;

/**
 * Polygene und liniengezuechtete Merkmale — Farbintensitaet, Groesse,
 * Zeichnungsstaerke.
 *
 * Hier gibt es keinen einzelnen Genort, den man auszaehlen koennte: Viele
 * Anlagen wirken zusammen, und das Ergebnis ist ein Uebergang, keine Klasse.
 * Der Simulator rechnet deshalb ein bewusst grobes additives Modell — ein
 * gedachter Genort, an dem sich die Auspraegung der Eltern mittelt. Was
 * herauskommt, ist eine Tendenz ("aus zwei kraeftig gefaerbten Tieren fallen
 * ueberwiegend kraeftig gefaerbte"), keine Wahrscheinlichkeit.
 *
 * Diese Einschraenkung wird als Warnung mitgegeben und nicht stillschweigend
 * hinter einer Prozentzahl versteckt. Eine Zahl, die niemand einloesen kann,
 * ist im Marktplatz schlimmer als keine Zahl.
 */
final readonly class PolygenicRule extends AutosomalRule
{
    public function supports(Inheritance $inheritance): bool
    {
        return $inheritance === Inheritance::Polygenic || $inheritance === Inheritance::LineBred;
    }

    public function express(Locus $locus, array $alleles, Sex $sex): array
    {
        $mutants = $this->mutantAlleles($alleles);

        if ($mutants === []) {
            return [];
        }

        // Im additiven Modell zeigt sich das Merkmal, sobald eine Anlage da
        // ist — bei einfacher Anlage schwaecher, bei doppelter staerker. Fuer
        // die Verteilung zaehlt nur, dass es sichtbar ist; wie stark, sagt
        // keine Rechnung voraus.
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
        if ($context->first->isWildtypeAt($locus->id) && $context->second->isWildtypeAt($locus->id)) {
            return [];
        }

        return [
            new GeneticWarning(
                GeneticWarning::TYPE_POLYGENIC,
                \sprintf(
                    '"%s" wird %s vererbt. Die Ausprägung ist ein Übergang und wird über Generationen '
                    . 'verstärkt — die Prozentangabe ist eine Tendenz, keine Vorhersage.',
                    $locus->label(),
                    $locus->inheritance->label(),
                ),
                WarningSeverity::Hinweis,
            ),
        ];
    }
}
