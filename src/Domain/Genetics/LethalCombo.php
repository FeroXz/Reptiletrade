<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

/**
 * Zwei Merkmale, die einzeln unauffaellig sind und zusammen nicht lebensfaehig.
 *
 * Das ist der Fall, den der Merkmalskatalog nicht abbilden kann: Sein
 * Kennzeichen is_lethal_combo haengt an einem einzelnen Merkmal und meint
 * dessen homozygote Form. Kombinationen ueber zwei Genorte hinweg stehen
 * deshalb in config/genetik.php.
 */
final readonly class LethalCombo
{
    /**
     * @param list<string> $morphs Merkmalsnamen, die zusammen auftreten muessen
     * @param float        $rate   Anteil der betroffenen Nachkommen, die nicht lebensfaehig sind
     */
    public function __construct(
        public array $morphs,
        public float $rate,
        public string $note,
    ) {}

    /**
     * @param list<string> $offspringMorphs
     */
    public function matches(array $offspringMorphs): bool
    {
        foreach ($this->morphs as $morph) {
            if (!\in_array($morph, $offspringMorphs, true)) {
                return false;
            }
        }

        return $this->morphs !== [];
    }
}
