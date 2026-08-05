<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use InvalidArgumentException;
use Reptilienmarkt\Domain\Listing\GeneticsCalculator;
use Reptilienmarkt\Domain\Listing\MorphSelection;
use Reptilienmarkt\Domain\Listing\MorphStringGenerator;
use Reptilienmarkt\Domain\Listing\Zygosity;

/**
 * Was man dem Tier ansieht — und was es nachweislich traegt.
 *
 * Ein Phaenotyp ist hier bewusst dieselbe Struktur wie die Merkmalsauswahl
 * einer Anzeige (MorphSelection): sichtbare Merkmale plus gesicherte
 * het-Angaben. Damit erzeugt der Simulator seine Nachzucht-Beschreibungen mit
 * genau demselben Generator wie der Anzeigenassistent seinen Morph-String —
 * "Hypo het Zero" heisst an beiden Stellen dasselbe. Zwei getrennte
 * Schreibweisen fuer dieselbe Sache waeren im Marktplatz ein Suchproblem.
 */
final readonly class Phenotype
{
    public const string WILDTYPE_LABEL = 'Wildtyp';

    /** @var list<MorphSelection> */
    private array $selections;

    /**
     * @param list<MorphSelection> $selections
     */
    public function __construct(array $selections)
    {
        foreach ($selections as $selection) {
            if (!$selection instanceof MorphSelection) {
                throw new InvalidArgumentException('Ein Phaenotyp besteht aus MorphSelection-Eintraegen.');
            }
        }

        $this->selections = array_values($selections);
    }

    public static function wildtype(): self
    {
        return new self([]);
    }

    /**
     * @return list<MorphSelection>
     */
    public function selections(): array
    {
        return $this->selections;
    }

    /**
     * Die sichtbaren Merkmale.
     *
     * @return list<string>
     */
    public function traits(): array
    {
        $names = [];

        foreach ($this->selections as $selection) {
            if ($selection->isVisual()) {
                $names[] = $selection->morph->name;
            }
        }

        sort($names);

        return array_values($names);
    }

    /**
     * Merkmale, die das Tier nachweislich traegt, ohne sie zu zeigen.
     *
     * @return list<string>
     */
    public function hets(): array
    {
        $names = [];

        foreach ($this->selections as $selection) {
            if ($selection->zygosity === Zygosity::Het) {
                $names[] = $selection->morph->name;
            }
        }

        sort($names);

        return array_values($names);
    }

    public function isWildtype(): bool
    {
        return $this->selections === [];
    }

    /**
     * Der Zuechter-String. Ohne jedes Merkmal steht dort nicht die leere
     * Zeichenkette, sondern "Wildtyp" — in einer Verteilung waere eine leere
     * Zeile nicht zu deuten.
     */
    public function morphString(GeneticsCalculator $calculator = new MorphStringGenerator()): string
    {
        $string = $calculator->morphString($this->selections);

        return $string === '' ? self::WILDTYPE_LABEL : $string;
    }

    public function genotypeString(GeneticsCalculator $calculator = new MorphStringGenerator()): string
    {
        return $calculator->genotype($this->selections);
    }

    public function equals(self $other): bool
    {
        return $this->signature() === $other->signature();
    }

    /**
     * Eindeutiger Schluessel fuer Verteilungen: Zwei Nachkommen mit denselben
     * Merkmalen in derselben Auspraegung sind derselbe Fall.
     */
    public function signature(): string
    {
        $parts = [];

        foreach ($this->selections as $selection) {
            $parts[] = $selection->morph->name . ':' . $selection->zygosity->value;
        }

        sort($parts);

        return implode('|', $parts);
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return [
            'sichtbar' => $this->traits(),
            'het' => $this->hets(),
        ];
    }
}
