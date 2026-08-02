<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Species;

/**
 * Vererbungsmodus eines Merkmals.
 */
enum Inheritance: string
{
    case Dominant = 'dominant';
    case IncompleteDominant = 'incomplete_dominant';
    case Recessive = 'recessive';
    case Polygenic = 'polygenic';
    case LineBred = 'line_bred';
    case Paradox = 'paradox';

    public function label(): string
    {
        return match ($this) {
            self::Dominant => 'dominant',
            self::IncompleteDominant => 'unvollständig dominant',
            self::Recessive => 'rezessiv',
            self::Polygenic => 'polygen',
            self::LineBred => 'liniengezüchtet',
            self::Paradox => 'Paradox',
        };
    }

    /**
     * Nur rezessive Merkmale koennen als het/poss. het gefuehrt werden.
     */
    public function allowsHeterozygous(): bool
    {
        return $this === self::Recessive;
    }
}
