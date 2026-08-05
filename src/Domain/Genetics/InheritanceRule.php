<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\MorphSelection;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Species\Inheritance;

/**
 * Eine Vererbungsregel rechnet einen einzelnen Genort.
 *
 * Anders als in der Lehrbuchdarstellung bekommt die Regel nicht die beiden
 * vollstaendigen Genotypen, sondern den Genort und den Zusammenhang. Der Grund
 * ist die Kombinatorik: Genorte vererben sich unabhaengig voneinander, und wer
 * sie zusammen rechnet, erzeugt Felder, die niemand liest, aber jeder
 * nachrechnen muss. Zusammengefuehrt wird erst in CrossSimulation.
 *
 * Ausdruecklich Teil der Regel ist auch die Auspraegung (express): Ob
 * "zero/zero" ein sichtbares Merkmal oder nur eine Anlage ist, haengt genau am
 * Erbgang — dieselbe Frage, dieselbe Klasse.
 */
interface InheritanceRule
{
    public function supports(Inheritance $inheritance): bool;

    /**
     * Das Punnett-Feld dieses Genorts fuer die im Zusammenhang genannte
     * Nachkommen-Auspraegung.
     */
    public function cross(Locus $locus, CrossContext $context): Punnett;

    /**
     * Hinweise zu dieser Verpaarung an diesem Genort.
     *
     * @return list<GeneticWarning>
     */
    public function warnings(Locus $locus, CrossContext $context): array;

    /**
     * Aus Allelen werden sichtbare Merkmale und Anlagen.
     *
     * @param list<string> $alleles
     *
     * @return list<MorphSelection>
     */
    public function express(Locus $locus, array $alleles, Sex $sex): array;
}
