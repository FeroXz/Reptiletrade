<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\MorphSelection;
use Reptilienmarkt\Domain\Species\Inheritance;

/**
 * Aus der Merkmalsangabe eines Tieres wird sein Genotyp — oder, wo die Angabe
 * unsicher ist, mehrere moegliche Genotypen mit ihrer Wahrscheinlichkeit.
 *
 * Der Umweg ueber mehrere Moeglichkeiten ist der Kern der Sache. Eine Angabe
 * wie "66 % poss. het Clown" heisst: Das Tier traegt die Anlage mit zwei
 * Dritteln Wahrscheinlichkeit. Wer daraus einen sicheren Traeger macht,
 * verspricht Nachkommen, die es in einem Drittel der Faelle nicht geben kann;
 * wer die Angabe verwirft, unterschlaegt, was der Zuechter weiss. Richtig ist
 * beides zusammen: Jede Moeglichkeit wird durchgerechnet und am Ende mit ihrem
 * Gewicht zusammengefuehrt.
 *
 * Nicht angegebene Genorte gelten als wildtypisch. Das ist eine Annahme, keine
 * Tatsache — ein unauffaelliges Tier kann Traeger von allem Moeglichen sein.
 * Sie ist die einzig vertretbare: Der Simulator rechnet mit dem, was bekannt
 * ist, und erfindet keine Anlagen.
 */
final readonly class GenotypeFactory
{
    /**
     * Mehr als so viele unsichere Angaben je Tier lassen sich nicht mehr
     * sinnvoll durchrechnen — und waeren auch als Aussage wertlos.
     */
    private const int MAX_UNCERTAIN_LOCI = 8;

    public function __construct(
        private LocusMap $map,
        private SexSystem $sexSystem = SexSystem::Zw,
    ) {}

    /**
     * Alle moeglichen Genotypen des Tieres mit ihrem Gewicht. Die Gewichte
     * summieren sich auf 1.
     *
     * @return list<array{genotype: Genotype, weight: float}>
     */
    public function possibilities(BreedingAnimal $animal): array
    {
        $certain = [];
        $uncertain = [];

        foreach ($this->contributions($animal) as $locusId => $entry) {
            if ($entry['probability'] >= 1.0) {
                $certain[$locusId] = $entry['alleles'];

                continue;
            }

            $uncertain[$locusId] = $entry;
        }

        if (\count($uncertain) > self::MAX_UNCERTAIN_LOCI) {
            throw new GeneticsException(\sprintf(
                'Bei mehr als %d unsicheren Merkmalsangaben ("poss. het") je Tier ist keine belastbare '
                . 'Verteilung mehr zu rechnen.',
                self::MAX_UNCERTAIN_LOCI,
            ));
        }

        /** @var list<array{genotype: array<string, list<string>>, weight: float}> $variants */
        $variants = [['genotype' => $certain, 'weight' => 1.0]];

        foreach ($uncertain as $locusId => $entry) {
            $expanded = [];

            foreach ($variants as $variant) {
                // Das Tier traegt die Anlage …
                $withTrait = $variant['genotype'];
                $withTrait[$locusId] = $entry['alleles'];
                $expanded[] = ['genotype' => $withTrait, 'weight' => $variant['weight'] * $entry['probability']];

                // … oder eben nicht.
                $expanded[] = ['genotype' => $variant['genotype'], 'weight' => $variant['weight'] * (1.0 - $entry['probability'])];
            }

            $variants = $expanded;
        }

        $result = [];
        foreach ($variants as $variant) {
            if ($variant['weight'] <= 0.0) {
                continue;
            }

            $result[] = ['genotype' => new Genotype($variant['genotype']), 'weight' => $variant['weight']];
        }

        // Die wahrscheinlichste Moeglichkeit zuerst: Sie liefert die
        // Punnett-Felder, die im Bericht abgebildet werden.
        usort($result, static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return $result;
    }

    /**
     * Der wahrscheinlichste Genotyp — fuer Anzeigen und Kurzfassungen, wo eine
     * Verteilung ueber Elterngenotypen nicht darstellbar ist.
     */
    public function mostLikely(BreedingAnimal $animal): Genotype
    {
        $possibilities = $this->possibilities($animal);

        return $possibilities === [] ? Genotype::empty() : $possibilities[0]['genotype'];
    }

    /**
     * Je Genort: welche Allele die Angabe behauptet und wie sicher.
     *
     * @return array<string, array{alleles: list<string>, probability: float}>
     */
    private function contributions(BreedingAnimal $animal): array
    {
        /** @var array<string, array{counts: array<string, int>, probability: float, hemizygous: bool}> $perLocus */
        $perLocus = [];

        foreach ($animal->morphs as $selection) {
            $morphId = $selection->morph->id;
            $placement = $morphId === null ? null : $this->map->placement($morphId);

            if ($placement === null) {
                continue;
            }

            $locus = $placement->locus;
            $hemizygous = $locus->isSexLinked() && $this->sexSystem->isHemizygous($animal->sex);

            $perLocus[$locus->id] ??= ['counts' => [], 'probability' => 1.0, 'hemizygous' => $hemizygous];
            $perLocus[$locus->id]['counts'][$placement->allele] ??= 0;
            $perLocus[$locus->id]['counts'][$placement->allele] += $this->copies($selection, $placement, $hemizygous);

            // Mehrere unsichere Angaben am selben Genort: Die niedrigere
            // Wahrscheinlichkeit gibt den Ausschlag.
            $perLocus[$locus->id]['probability'] = min(
                $perLocus[$locus->id]['probability'],
                $selection->zygosity->probability(),
            );
        }

        $contributions = [];

        foreach ($perLocus as $locusId => $entry) {
            $alleles = $this->assemble($entry['counts'], $entry['hemizygous']);

            if ($alleles === []) {
                continue;
            }

            $contributions[$locusId] = ['alleles' => $alleles, 'probability' => $entry['probability']];
        }

        return $contributions;
    }

    /**
     * Wie viele Allele behauptet diese Angabe?
     */
    private function copies(MorphSelection $selection, LocusPlacement $placement, bool $hemizygous): int
    {
        if (!$selection->isVisual()) {
            return 1;
        }

        if ($hemizygous) {
            return 1;
        }

        if ($placement->isSuperForm) {
            return 2;
        }

        return match ($placement->locus->inheritance) {
            // Sichtbar und rezessiv heisst reinerbig — es sei denn, am selben
            // Genort sitzt eine zweite Anlage; das faengt assemble() ab.
            Inheritance::Recessive, Inheritance::SexLinked => 2,
            // Dominante und unvollstaendig dominante Merkmale zeigen sich schon
            // mit einem Allel. Ob das Tier reinerbig ist, weiss die Anzeige
            // nicht — die Superform stuende sonst im Katalog.
            Inheritance::Dominant, Inheritance::IncompleteDominant => 1,
            // Polygene und liniengezuechtete Merkmale: volle Auspraegung im
            // additiven Modell.
            default => 2,
        };
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<string>
     */
    private function assemble(array $counts, bool $hemizygous): array
    {
        $alleles = array_keys($counts);

        if ($alleles === []) {
            return [];
        }

        if ($hemizygous) {
            return [$alleles[0]];
        }

        // Zwei verschiedene Anlagen an einem Genort: Das Tier traegt je eine —
        // etwas anderes ist genetisch nicht moeglich.
        if (\count($alleles) >= 2) {
            return Genotype::sortAlleles([$alleles[0], $alleles[1]]);
        }

        $allele = $alleles[0];

        return $counts[$allele] >= 2
            ? [$allele, $allele]
            : Genotype::sortAlleles([$allele, Genotype::WILDTYPE]);
    }
}
