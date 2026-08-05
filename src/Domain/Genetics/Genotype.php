<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use InvalidArgumentException;

/**
 * Der Genotyp eines Tieres: je Genort die Allele, die es traegt.
 *
 *     ['zero_witblits' => ['zero', '+'], 'leatherback' => ['leatherback', 'leatherback']]
 *
 * Zwei Allele je Genort sind der Normalfall. Genau eines steht fuer
 * Hemizygotie: Bei geschlechtsgebundenen Merkmalen traegt das heterogametische
 * Geschlecht (bei Reptilien mit ZW-System das Weibchen) nur ein Z-Chromosom und
 * damit nur ein Allel. Das ist kein Sonderfall der Darstellung, sondern der
 * Grund, warum solche Merkmale sich anders vererben.
 *
 * Das Wildtyp-Allel heisst "+" wie in der Zuchtnotation. Es ist ein Allel wie
 * jedes andere — "kein Merkmal" gibt es genetisch nicht, es gibt nur das
 * unveraenderte Allel.
 *
 * Die Allele werden beim Anlegen sortiert (Wildtyp zuletzt) und die Genorte
 * nach Schluessel. Dadurch ist die Darstellung eindeutig: derselbe Genotyp
 * ergibt immer dieselbe Kurzform und denselben Verteilungsschluessel,
 * unabhaengig davon, in welcher Reihenfolge er zusammengesetzt wurde.
 */
final readonly class Genotype
{
    public const string WILDTYPE = '+';

    /** @var array<string, list<string>> */
    private array $loci;

    /**
     * @param array<string, list<string>> $loci Genort => Allele (ein oder zwei)
     *
     * @throws InvalidArgumentException
     */
    public function __construct(array $loci)
    {
        $normalised = [];

        foreach ($loci as $locus => $alleles) {
            if (!\is_string($locus) || trim($locus) === '') {
                throw new InvalidArgumentException('Ein Genort braucht einen nicht leeren Schluessel.');
            }

            if (!\is_array($alleles)) {
                throw new InvalidArgumentException(\sprintf('Genort "%s": Allele muessen als Liste vorliegen.', $locus));
            }

            $count = \count($alleles);
            if ($count < 1 || $count > 2) {
                throw new InvalidArgumentException(\sprintf(
                    'Genort "%s": erwartet werden ein Allel (hemizygot) oder zwei — angegeben sind %d.',
                    $locus,
                    $count,
                ));
            }

            $clean = [];
            foreach ($alleles as $allele) {
                if (!\is_string($allele) || trim($allele) === '') {
                    throw new InvalidArgumentException(\sprintf('Genort "%s": Allele muessen nicht leere Zeichenketten sein.', $locus));
                }

                $clean[] = $allele;
            }

            $normalised[$locus] = self::sortAlleles($clean);
        }

        ksort($normalised);

        $this->loci = $normalised;
    }

    /**
     * @param array<string, list<string>> $loci
     */
    public static function of(array $loci): self
    {
        return new self($loci);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Aus gespeichertem JSON. Fremde Daten werden wie Eingaben behandelt —
     * die Pruefung im Konstruktor gilt auch hier.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $loci = [];

        foreach ($data as $locus => $alleles) {
            if (!\is_string($locus)) {
                throw new InvalidArgumentException('Genort-Schluessel muessen Zeichenketten sein.');
            }

            if (!\is_array($alleles)) {
                throw new InvalidArgumentException(\sprintf('Genort "%s": Allele muessen als Liste vorliegen.', $locus));
            }

            $list = [];
            foreach ($alleles as $allele) {
                if (!\is_string($allele)) {
                    throw new InvalidArgumentException(\sprintf('Genort "%s": Allele muessen Zeichenketten sein.', $locus));
                }

                $list[] = $allele;
            }

            $loci[$locus] = $list;
        }

        return new self($loci);
    }

    /**
     * @return array<string, list<string>>
     */
    public function alleles(): array
    {
        return $this->loci;
    }

    /**
     * Die Allele eines Genorts. Ein nicht belegter Genort liefert den Wildtyp,
     * denn ein Tier hat dort trotzdem Allele — nur eben die unveraenderten.
     *
     * @return list<string>
     */
    public function at(string $locus, bool $hemizygous = false): array
    {
        return $this->loci[$locus] ?? ($hemizygous ? [self::WILDTYPE] : [self::WILDTYPE, self::WILDTYPE]);
    }

    public function has(string $locus): bool
    {
        return isset($this->loci[$locus]);
    }

    /**
     * @return list<string>
     */
    public function loci(): array
    {
        return array_keys($this->loci);
    }

    public function isHomozygous(string $locus): bool
    {
        $alleles = $this->loci[$locus] ?? null;

        return $alleles !== null && \count($alleles) === 2 && $alleles[0] === $alleles[1];
    }

    public function isHeterozygous(string $locus): bool
    {
        $alleles = $this->loci[$locus] ?? null;

        return $alleles !== null && \count($alleles) === 2 && $alleles[0] !== $alleles[1];
    }

    /**
     * Nur ein Allel — das heterogametische Geschlecht bei geschlechtsgebundenen
     * Merkmalen. "het" gibt es hier nicht: Was auf dem einen Chromosom liegt,
     * ist sichtbar, weil kein zweites Allel es verdecken kann.
     */
    public function isHemizygous(string $locus): bool
    {
        $alleles = $this->loci[$locus] ?? null;

        return $alleles !== null && \count($alleles) === 1;
    }

    public function carries(string $locus, string $allele): bool
    {
        return \in_array($allele, $this->loci[$locus] ?? [], true);
    }

    /**
     * Wie oft traegt das Tier das Allel an diesem Genort? Grundlage des
     * additiven Modells bei polygenen Merkmalen.
     */
    public function dosage(string $locus, string $allele): int
    {
        $count = 0;

        foreach ($this->loci[$locus] ?? [] as $candidate) {
            if ($candidate === $allele) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Traegt das Tier an diesem Genort ueberhaupt ein Merkmal — oder nur Wildtyp?
     */
    public function isWildtypeAt(string $locus): bool
    {
        foreach ($this->loci[$locus] ?? [] as $allele) {
            if ($allele !== self::WILDTYPE) {
                return false;
            }
        }

        return true;
    }

    public function with(string $locus, string ...$alleles): self
    {
        $loci = $this->loci;
        $loci[$locus] = array_values($alleles);

        return new self($loci);
    }

    /**
     * Kurzform eines Genorts, z. B. "zero/+".
     */
    public function notation(string $locus): string
    {
        return implode('/', $this->at($locus));
    }

    /**
     * Genetische Gleichheit — unabhaengig davon, zu welchem Tier der Genotyp gehoert.
     */
    public function equals(self $other): bool
    {
        return $this->loci === $other->loci;
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return $this->loci;
    }

    /**
     * Wildtyp zuletzt, sonst alphabetisch: "zero/+" statt "+/zero" — so steht
     * das Merkmal vorn, und genau danach sucht ein Zuechter.
     *
     * @param list<string> $alleles
     *
     * @return list<string>
     */
    public static function sortAlleles(array $alleles): array
    {
        usort($alleles, static function (string $a, string $b): int {
            if ($a === $b) {
                return 0;
            }

            if ($a === self::WILDTYPE) {
                return 1;
            }

            if ($b === self::WILDTYPE) {
                return -1;
            }

            return strcmp($a, $b);
        });

        return $alleles;
    }
}
