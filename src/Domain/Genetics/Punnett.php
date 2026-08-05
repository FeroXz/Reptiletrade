<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use InvalidArgumentException;

/**
 * Das Punnett-Quadrat eines Genorts: die Gameten des einen Elterntiers gegen
 * die des anderen.
 *
 * Es wird je Genort gerechnet und erst danach kombiniert. Ein einziges grosses
 * Feld ueber alle Merkmale haette bei sechs Merkmalen 4096 Felder — niemand
 * liest das, und die Rechnung waere dieselbe. Die Felder bleiben einzeln
 * erhalten, weil genau sie im Bericht abgebildet werden.
 *
 * Nicht lebensfaehige Kombinationen bleiben im Feld stehen und werden
 * gekennzeichnet, statt sie herauszurechnen. Ein Zuechter will wissen, dass ein
 * Viertel des Geleges nicht schluepft — nicht nur, wie sich der Rest verteilt.
 */
final readonly class Punnett
{
    /**
     * @param list<string>             $rowGametes    Gameten des ersten Elterntiers
     * @param list<string>             $columnGametes Gameten des zweiten Elterntiers
     * @param list<list<list<string>>> $cells         [Zeile][Spalte] => Allele des Nachkommen
     * @param list<string>             $lethal        Genotyp-Kurzformen, die nicht lebensfaehig sind
     */
    public function __construct(
        public string $locusId,
        public string $label,
        private array $rowGametes,
        private array $columnGametes,
        private array $cells,
        private array $lethal = [],
    ) {
        if ($cells === []) {
            throw new InvalidArgumentException('Ein Punnett-Feld ohne Felder ergibt keine Verteilung.');
        }
    }

    /**
     * Der Normalfall: Jede Gamete des einen Elterntiers trifft auf jede des
     * anderen, das Ergebnis traegt beide Allele.
     *
     * @param list<string> $rowGametes
     * @param list<string> $columnGametes
     * @param list<string> $lethal
     */
    public static function fromGametes(
        string $locusId,
        string $label,
        array $rowGametes,
        array $columnGametes,
        array $lethal = [],
    ): self {
        $cells = [];

        foreach ($rowGametes as $rowAllele) {
            $row = [];
            foreach ($columnGametes as $columnAllele) {
                $row[] = Genotype::sortAlleles([$rowAllele, $columnAllele]);
            }
            $cells[] = $row;
        }

        return new self($locusId, $label, $rowGametes, $columnGametes, $cells, $lethal);
    }

    /**
     * @return list<string>
     */
    public function rows(): array
    {
        return $this->rowGametes;
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->columnGametes;
    }

    /**
     * Das Feld in Kurzform, z. B. [['zero/zero', 'zero/+'], ['zero/+', '+/+']].
     *
     * @return list<list<string>>
     */
    public function grid(): array
    {
        $grid = [];

        foreach ($this->cells as $row) {
            $line = [];
            foreach ($row as $alleles) {
                $line[] = self::notation($alleles);
            }
            $grid[] = $line;
        }

        return $grid;
    }

    /**
     * Alle eindeutigen Genotypen des Feldes, haeufigste zuerst.
     *
     * @return list<string>
     */
    public function genotypes(): array
    {
        return array_keys($this->distribution());
    }

    /**
     * Haeufigkeitsverteilung einschliesslich nicht lebensfaehiger Kombinationen.
     *
     * @return array<string, float>
     */
    public function distribution(): array
    {
        $counts = [];
        $total = 0;

        foreach ($this->cells as $row) {
            foreach ($row as $alleles) {
                $key = self::notation($alleles);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                ++$total;
            }
        }

        $distribution = [];
        foreach ($counts as $key => $count) {
            // Ohne Umwandlung liefert PHP bei aufgehenden Divisionen ein int —
            // und die Verteilung haette gemischte Typen.
            $distribution[$key] = (float) ($count / $total);
        }

        arsort($distribution);

        return $distribution;
    }

    /**
     * Die Verteilung unter den lebensfaehigen Nachkommen.
     *
     * @return array<string, float>
     */
    public function viableDistribution(): array
    {
        $viable = [];
        $sum = 0.0;

        foreach ($this->distribution() as $genotype => $share) {
            if ($this->isLethal($genotype)) {
                continue;
            }

            $viable[$genotype] = $share;
            $sum += $share;
        }

        if ($sum <= 0.0) {
            return [];
        }

        foreach ($viable as $genotype => $share) {
            $viable[$genotype] = $share / $sum;
        }

        return $viable;
    }

    public function lethalShare(): float
    {
        $share = 0.0;

        foreach ($this->distribution() as $genotype => $probability) {
            if ($this->isLethal($genotype)) {
                $share += $probability;
            }
        }

        return $share;
    }

    public function isLethal(string $genotype): bool
    {
        return \in_array($genotype, $this->lethal, true);
    }

    /**
     * @return list<string>
     */
    public function lethalGenotypes(): array
    {
        return $this->lethal;
    }

    /**
     * Die Allele hinter einer Kurzform.
     *
     * @return list<string>
     */
    public function allelesOf(string $genotype): array
    {
        foreach ($this->cells as $row) {
            foreach ($row as $alleles) {
                if (self::notation($alleles) === $genotype) {
                    return $alleles;
                }
            }
        }

        return [];
    }

    /**
     * @param list<string> $lethal
     */
    public function withLethal(array $lethal): self
    {
        return new self($this->locusId, $this->label, $this->rowGametes, $this->columnGametes, $this->cells, $lethal);
    }

    public function withLabel(string $label): self
    {
        return new self($this->locusId, $label, $this->rowGametes, $this->columnGametes, $this->cells, $this->lethal);
    }

    /**
     * @param list<string> $alleles
     */
    public static function notation(array $alleles): string
    {
        return implode('/', Genotype::sortAlleles($alleles));
    }

    /**
     * @return array{genort: string, bezeichnung: string, zeilen: list<string>, spalten: list<string>, felder: list<list<string>>, letal: list<string>, verteilung: array<string, float>}
     */
    public function toArray(): array
    {
        return [
            'genort' => $this->locusId,
            'bezeichnung' => $this->label,
            'zeilen' => $this->rowGametes,
            'spalten' => $this->columnGametes,
            'felder' => $this->grid(),
            'letal' => $this->lethal,
            'verteilung' => $this->distribution(),
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $rows = self::stringList($data['zeilen'] ?? []);
        $columns = self::stringList($data['spalten'] ?? []);
        $lethal = self::stringList($data['letal'] ?? []);

        $cells = [];
        $grid = $data['felder'] ?? [];
        if (\is_array($grid)) {
            foreach ($grid as $row) {
                if (!\is_array($row)) {
                    continue;
                }

                $line = [];
                foreach ($row as $notation) {
                    $line[] = \is_string($notation) ? explode('/', $notation) : [];
                }
                $cells[] = $line;
            }
        }

        return new self(
            \is_string($data['genort'] ?? null) ? $data['genort'] : '',
            \is_string($data['bezeichnung'] ?? null) ? $data['bezeichnung'] : '',
            $rows,
            $columns,
            $cells,
            $lethal,
        );
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $entry) {
            if (\is_string($entry)) {
                $list[] = $entry;
            }
        }

        return $list;
    }
}
