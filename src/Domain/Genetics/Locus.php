<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Species\Inheritance;
use Reptilienmarkt\Domain\Species\Morph;

/**
 * Ein Genort und die Allele, die dort sitzen koennen.
 *
 * Der Genort ist die Einheit, in der gerechnet wird — nicht das einzelne
 * Merkmal. Zwei Merkmale derselben Allelgruppe (Zero und Witblits bei der
 * Bartagame) besetzen denselben Ort: Ein Tier kann beide nur zusammen auf zwei
 * Chromosomen tragen, nie unabhaengig voneinander. Wer je Merkmal rechnet,
 * bekommt hier Nachkommen heraus, die es nicht geben kann.
 *
 * Die Superform (Silkback zu Leatherback) ist kein eigenes Allel, sondern die
 * homozygote Auspraegung des Basisallels. Sie steht deshalb neben den Allelen,
 * nicht zwischen ihnen.
 */
final readonly class Locus
{
    /**
     * @param array<string, Morph> $morphs     Allel => Merkmal (ohne Wildtyp)
     * @param array<string, Morph> $superForms Allel => Merkmal der homozygoten Auspraegung
     */
    public function __construct(
        public string $id,
        public Inheritance $inheritance,
        public array $morphs,
        public array $superForms = [],
    ) {}

    /**
     * Alle Allele dieses Genorts, Wildtyp eingeschlossen.
     *
     * @return list<string>
     */
    public function alleles(): array
    {
        $alleles = array_keys($this->morphs);
        $alleles[] = Genotype::WILDTYPE;

        return $alleles;
    }

    public function morph(string $allele): ?Morph
    {
        return $this->morphs[$allele] ?? null;
    }

    public function superForm(string $allele): ?Morph
    {
        return $this->superForms[$allele] ?? null;
    }

    public function knows(string $allele): bool
    {
        return $allele === Genotype::WILDTYPE || isset($this->morphs[$allele]);
    }

    /**
     * Ist die homozygote Form nicht lebensfaehig? Das Kennzeichen sitzt am
     * Merkmal (morphs.is_lethal_combo) und meint genau diesen Fall — die
     * Verpaarung zweier Traeger, bei der ein Viertel der Nachkommen die
     * doppelte Anlage bekommt.
     */
    public function isLethalWhenHomozygous(string $allele): bool
    {
        $morph = $this->morphs[$allele] ?? null;
        if ($morph !== null && $morph->isLethalCombo) {
            return true;
        }

        $super = $this->superForms[$allele] ?? null;

        return $super !== null && $super->isLethalCombo;
    }

    /**
     * Genorte mit mehr als einem Merkmal sind allelisch — dort sind
     * Mischerbige (Zero/Witblits) sichtbar und nicht etwa Traeger.
     */
    public function isMultiAllelic(): bool
    {
        return \count($this->morphs) > 1;
    }

    /**
     * Sprechender Name fuer Berichte: die Merkmale dieses Orts.
     */
    public function label(): string
    {
        $names = [];
        foreach ($this->morphs as $morph) {
            $names[] = $morph->name;
        }

        sort($names);

        return $names === [] ? $this->id : implode(' / ', $names);
    }

    /**
     * Liegt das Merkmal auf einem Geschlechtschromosom?
     */
    public function isSexLinked(): bool
    {
        return $this->inheritance === Inheritance::SexLinked;
    }
}
