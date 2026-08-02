<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

/**
 * Auspraegung eines Merkmals am konkreten Tier.
 */
enum Zygosity: string
{
    case Visual = 'visual';
    case Het = 'het';
    case PossHet66 = 'poss_het_66';
    case PossHet50 = 'poss_het_50';

    public function label(): string
    {
        return match ($this) {
            self::Visual => 'sichtbar',
            self::Het => 'het',
            self::PossHet66 => '66 % poss. het',
            self::PossHet50 => '50 % poss. het',
        };
    }

    /**
     * Kuerzel fuer den generierten Morph-String, z. B. "Hypo het Zero".
     */
    public function prefix(): string
    {
        return match ($this) {
            self::Visual => '',
            self::Het => 'het',
            self::PossHet66 => '66% poss. het',
            self::PossHet50 => '50% poss. het',
        };
    }

    public function isVisual(): bool
    {
        return $this === self::Visual;
    }
}
