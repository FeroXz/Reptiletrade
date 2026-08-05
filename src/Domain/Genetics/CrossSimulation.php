<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\GeneticsCalculator;
use Reptilienmarkt\Domain\Listing\MorphStringGenerator;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Species\MorphRepository;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Support\Clock;

/**
 * Die Verpaarungssimulation.
 *
 * Der Ablauf in einem Satz: Aus den Merkmalsangaben beider Tiere werden
 * moegliche Genotypen, je Genort ein Punnett-Quadrat, aus den Quadraten die
 * Kombinationen aller Genorte, und aus jeder Kombination ein Phaenotyp mit
 * seinem Anteil.
 *
 * Drei Entscheidungen praegen das Ergebnis:
 *
 * - Es wird je Genort gerechnet und erst danach kombiniert. Genorte vererben
 *   sich unabhaengig voneinander; ein gemeinsames Feld ueber alle Merkmale
 *   waere dieselbe Rechnung in unleserlich.
 * - Unsichere Angaben ("66 % poss. het") werden nicht auf sicher oder
 *   nicht-vorhanden gerundet, sondern als gewichtete Moeglichkeiten
 *   durchgerechnet.
 * - Nicht lebensfaehige Nachkommen werden ausgewiesen und nicht
 *   herausgekuerzt. Die Verteilung bezieht sich auf die lebensfaehigen Tiere,
 *   der Ausfall steht daneben.
 *
 * Die Simulation aendert nichts: kein Schreibzugriff, kein Zustand, keine
 * Zufallszahlen. Dieselben Eltern ergeben dieselbe Verteilung — sonst waere ein
 * gespeicherter Bericht nicht nachvollziehbar.
 */
final readonly class CrossSimulation
{
    public function __construct(
        private MorphRepository $morphs,
        private SpeciesRepository $species,
        private GeneticsConfiguration $config,
        private Clock $clock,
        private GeneticsCalculator $calculator = new MorphStringGenerator(),
        private ?RuleRegistry $rules = null,
    ) {}

    /**
     * @throws IncompatibleSpeciesException wenn die Tiere verschiedenen Arten angehoeren
     * @throws LethalCrossException         wenn kein lebensfaehiger Nachkomme moeglich ist
     * @throws GeneticsException
     */
    public function cross(BreedingAnimal $first, BreedingAnimal $second): SimulationResult
    {
        if ($first->speciesId !== $second->speciesId) {
            throw new IncompatibleSpeciesException(
                'Die beiden Tiere gehören verschiedenen Arten an. Eine Verpaarung ist nicht möglich.',
            );
        }

        $species = $this->species->findById($first->speciesId)
            ?? throw new GeneticsException('Zu dieser Art liegt kein Eintrag im Artenstamm vor.');

        $rules = $this->rules ?? RuleRegistry::default();
        $sexSystem = $this->config->sexSystem($species->slug);
        $map = LocusMap::build($this->morphs->forSpecies($first->speciesId), $this->config->superForms($species->slug));
        $factory = new GenotypeFactory($map, $sexSystem);
        $resolver = new PhenotypeResolver($map, $rules);

        $variantsFirst = $factory->possibilities($first);
        $variantsSecond = $factory->possibilities($second);
        $loci = $this->involvedLoci($map, $variantsFirst, $variantsSecond);

        $phenotypes = [];
        $bySex = [Sex::Maennlich->value => [], Sex::Weiblich->value => []];
        $genotypes = [];
        $morphShares = [];
        $punnetts = [];
        $warnings = [];
        $lethalShare = 0.0;

        foreach ($variantsFirst as $indexFirst => $variantFirst) {
            foreach ($variantsSecond as $indexSecond => $variantSecond) {
                $pairWeight = $variantFirst['weight'] * $variantSecond['weight'];

                if ($pairWeight <= 0.0) {
                    continue;
                }

                // Die Felder im Bericht stammen aus der wahrscheinlichsten
                // Elternkombination. Alle Varianten abzubilden waere richtig,
                // aber unlesbar — die Verteilung selbst enthaelt sie ohnehin.
                $primary = $indexFirst === 0 && $indexSecond === 0;

                foreach ([Sex::Maennlich, Sex::Weiblich] as $offspringSex) {
                    $context = new CrossContext(
                        $variantFirst['genotype'],
                        $first->sex,
                        $variantSecond['genotype'],
                        $second->sex,
                        $offspringSex,
                        $sexSystem,
                    );

                    $fields = [];
                    foreach ($loci as $locus) {
                        $rule = $rules->ruleFor($locus);
                        $punnett = $rule->cross($locus, $context);
                        $fields[$locus->id] = $punnett;

                        foreach ($rule->warnings($locus, $context) as $warning) {
                            $warnings[$warning->key()] ??= $warning;
                        }

                        if ($primary) {
                            $key = $locus->isSexLinked() ? $locus->id . ':' . $offspringSex->value : $locus->id;
                            $punnetts[$key] ??= $locus->isSexLinked()
                                ? $punnett->withLabel($locus->label() . ' — ' . SimulationResult::offspringLabel($offspringSex))
                                : $punnett;
                        }
                    }

                    // Jedes Geschlecht macht die Haelfte der Nachzucht aus.
                    $weight = $pairWeight * 0.5;

                    foreach ($this->combine($fields) as $combination) {
                        $probability = $combination['probability'] * $weight;

                        if ($probability <= 0.0) {
                            continue;
                        }

                        if ($combination['lethal']) {
                            $lethalShare += $probability;

                            continue;
                        }

                        $genotype = new Genotype($combination['alleles']);
                        $phenotype = $resolver->resolve($genotype, $offspringSex);
                        $traits = $phenotype->traits();

                        $comboRate = $this->lethalComboRate($species, $traits, $warnings);
                        if ($comboRate > 0.0) {
                            $lethalShare += $probability * $comboRate;
                            $probability *= 1.0 - $comboRate;

                            if ($probability <= 0.0) {
                                continue;
                            }
                        }

                        $label = $phenotype->morphString($this->calculator);
                        $phenotypes[$label] = ($phenotypes[$label] ?? 0.0) + $probability;
                        $bySex[$offspringSex->value][$label] = ($bySex[$offspringSex->value][$label] ?? 0.0) + $probability;

                        $notation = $this->genotypeNotation($genotype);
                        $genotypes[$notation] = ($genotypes[$notation] ?? 0.0) + $probability;

                        foreach ($traits as $trait) {
                            $morphShares[$trait] = ($morphShares[$trait] ?? 0.0) + $probability;
                        }
                    }
                }
            }
        }

        $viable = array_sum($phenotypes);

        if ($viable <= 0.0) {
            throw new LethalCrossException(
                'Aus dieser Verpaarung kann kein lebensfähiger Nachkomme hervorgehen.',
            );
        }

        foreach ($this->welfareWarnings($species, $morphShares, $viable) as $warning) {
            $warnings[$warning->key()] ??= $warning;
        }

        foreach ($this->inputWarnings($first, $second) as $warning) {
            $warnings[$warning->key()] ??= $warning;
        }

        $parentFirst = $this->summarise($first, $factory, $species);
        $parentSecond = $this->summarise($second, $factory, $species);

        return new SimulationResult(
            $parentFirst,
            $parentSecond,
            $species->commonNameDe . ' (' . $species->scientificName . ')',
            $punnetts,
            $this->sortWarnings($warnings),
            $this->normalise($phenotypes),
            [
                Sex::Maennlich->value => $this->normalise($bySex[Sex::Maennlich->value]),
                Sex::Weiblich->value => $this->normalise($bySex[Sex::Weiblich->value]),
            ],
            $this->normalise($genotypes),
            $lethalShare,
            $this->config->clutch($species->slug),
            $this->clock->now(),
        );
    }

    /**
     * Die Genorte, an denen ueberhaupt etwas passiert. Wildtypische Genorte
     * ergaeben in jeder Zeile dasselbe Ergebnis und wuerden die Kombinatorik
     * ohne Erkenntnisgewinn vervielfachen.
     *
     * @param list<array{genotype: Genotype, weight: float}> $variantsFirst
     * @param list<array{genotype: Genotype, weight: float}> $variantsSecond
     *
     * @return list<Locus>
     */
    private function involvedLoci(LocusMap $map, array $variantsFirst, array $variantsSecond): array
    {
        $ids = [];

        foreach ([...$variantsFirst, ...$variantsSecond] as $variant) {
            foreach ($variant['genotype']->loci() as $locusId) {
                if (!$variant['genotype']->isWildtypeAt($locusId)) {
                    $ids[$locusId] = true;
                }
            }
        }

        $loci = [];
        foreach (array_keys($ids) as $locusId) {
            $locus = $map->get($locusId);
            if ($locus !== null) {
                $loci[] = $locus;
            }
        }

        return $loci;
    }

    /**
     * Kartesisches Produkt ueber die Genorte: Jede Kombination von
     * Genort-Ergebnissen ist ein moeglicher Nachkomme.
     *
     * @param array<string, Punnett> $fields
     *
     * @return list<array{alleles: array<string, list<string>>, probability: float, lethal: bool}>
     */
    private function combine(array $fields): array
    {
        /** @var list<array{alleles: array<string, list<string>>, probability: float, lethal: bool}> $combinations */
        $combinations = [['alleles' => [], 'probability' => 1.0, 'lethal' => false]];
        $limit = $this->config->maxCombinations();

        foreach ($fields as $locusId => $punnett) {
            $next = [];

            foreach ($punnett->distribution() as $genotype => $probability) {
                $alleles = $punnett->allelesOf($genotype);
                $lethal = $punnett->isLethal($genotype);

                foreach ($combinations as $combination) {
                    $combination['alleles'][$locusId] = $alleles;
                    $combination['probability'] *= $probability;
                    $combination['lethal'] = $combination['lethal'] || $lethal;
                    $next[] = $combination;
                }
            }

            if (\count($next) > $limit) {
                throw new GeneticsException(\sprintf(
                    'Diese Verpaarung ergibt mehr als %d Genotyp-Kombinationen. Bitte rechne die Merkmale in '
                    . 'kleineren Gruppen durch.',
                    $limit,
                ));
            }

            $combinations = $next;
        }

        return $combinations;
    }

    /**
     * Anteil der Nachkommen dieses Phaenotyps, die an einer hinterlegten
     * Letalkombination scheitern.
     *
     * @param list<string>                 $traits
     * @param array<string, GeneticWarning> $warnings
     */
    private function lethalComboRate(Species $species, array $traits, array &$warnings): float
    {
        $survival = 1.0;

        foreach ($this->config->lethalCombos($species->slug) as $combo) {
            if (!$combo->matches($traits)) {
                continue;
            }

            $survival *= 1.0 - $combo->rate;

            $warning = new GeneticWarning(
                GeneticWarning::TYPE_LETHAL,
                \sprintf(
                    '"%s" zusammen sind nicht lebensfähig. %s',
                    implode('" und "', $combo->morphs),
                    $combo->note,
                ),
                WarningSeverity::Fehler,
                $combo->rate,
            );

            $warnings[$warning->key()] ??= $warning;
        }

        return 1.0 - $survival;
    }

    /**
     * Tierschutzhinweise zu Merkmalen, die in der Nachzucht auftreten koennen.
     *
     * @param array<string, float> $morphShares
     *
     * @return list<GeneticWarning>
     */
    private function welfareWarnings(Species $species, array $morphShares, float $viable): array
    {
        $warnings = [];

        foreach ($this->config->welfareNotes($species->slug) as $morph => $note) {
            $share = $morphShares[$morph] ?? 0.0;

            if ($share <= 0.0) {
                continue;
            }

            $warnings[] = new GeneticWarning(
                GeneticWarning::TYPE_WELFARE,
                \sprintf('%s: %s', $morph, $note),
                WarningSeverity::Warnung,
                $viable > 0.0 ? $share / $viable : null,
            );
        }

        return $warnings;
    }

    /**
     * Hinweise, die schon an der Eingabe haengen — unstimmige Angaben und
     * Unsicherheiten, die sich auf das ganze Ergebnis auswirken.
     *
     * @return list<GeneticWarning>
     */
    private function inputWarnings(BreedingAnimal $first, BreedingAnimal $second): array
    {
        $warnings = [];

        foreach ([$first, $second] as $animal) {
            foreach ($this->calculator->warnings($animal->morphs) as $message) {
                $warnings[] = new GeneticWarning(GeneticWarning::TYPE_UNCERTAIN, $message, WarningSeverity::Warnung);
            }
        }

        if ($first->sex !== Sex::Unbekannt && $first->sex === $second->sex) {
            $warnings[] = new GeneticWarning(
                GeneticWarning::TYPE_SEX,
                \sprintf(
                    'Beide Tiere sind als %s angegeben. Die Rechnung läuft trotzdem durch, das Ergebnis ist aber '
                    . 'ein Gedankenspiel.',
                    $first->sex->label(),
                ),
                WarningSeverity::Warnung,
            );
        }

        if ($first->hasUncertainTraits() || $second->hasUncertainTraits()) {
            $warnings[] = new GeneticWarning(
                GeneticWarning::TYPE_UNCERTAIN,
                'Mindestens ein Elterntier trägt eine unsichere Angabe ("poss. het"). Die Wahrscheinlichkeit, '
                . 'dass die Anlage überhaupt vorhanden ist, steckt bereits in den Prozentzahlen.',
                WarningSeverity::Hinweis,
            );
        }

        return $warnings;
    }

    private function summarise(BreedingAnimal $animal, GenotypeFactory $factory, Species $species): ParentSummary
    {
        $genotype = $factory->mostLikely($animal);
        $morphString = $this->calculator->morphString($animal->morphs);
        $morphString = $morphString === '' ? Phenotype::WILDTYPE_LABEL : $morphString;

        $label = $animal->label !== ''
            ? $animal->label
            : \sprintf('%s, %s – %s', $species->commonNameDe, $animal->sex->label(), $morphString);

        return new ParentSummary(
            $label,
            $animal->sex,
            $morphString,
            $this->calculator->genotype($animal->morphs),
            $genotype->toArray(),
            $animal->listingId,
        );
    }

    /**
     * Genotyp-Kurzform ueber alle Genorte, z. B. "leatherback/+ zero/+".
     */
    private function genotypeNotation(Genotype $genotype): string
    {
        $parts = [];

        foreach ($genotype->loci() as $locusId) {
            if ($genotype->isWildtypeAt($locusId)) {
                continue;
            }

            $parts[] = $genotype->notation($locusId);
        }

        return $parts === [] ? '+/+' : implode(' ', $parts);
    }

    /**
     * @param array<string, float> $distribution
     *
     * @return array<string, float>
     */
    private function normalise(array $distribution): array
    {
        $sum = array_sum($distribution);

        if ($sum <= 0.0) {
            return [];
        }

        foreach ($distribution as $key => $value) {
            $distribution[$key] = $value / $sum;
        }

        arsort($distribution);

        return $distribution;
    }

    /**
     * @param array<string, GeneticWarning> $warnings
     *
     * @return list<GeneticWarning>
     */
    private function sortWarnings(array $warnings): array
    {
        $list = array_values($warnings);

        usort(
            $list,
            static fn(GeneticWarning $a, GeneticWarning $b): int => $b->severity->weight() <=> $a->severity->weight()
                ?: strcmp($a->message, $b->message),
        );

        return $list;
    }
}
