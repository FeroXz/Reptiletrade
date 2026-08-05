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

    /**
     * Mit welcher Wahrscheinlichkeit traegt das Tier die Anlage tatsaechlich?
     *
     * "66 % poss. het" ist keine Auspraegung, sondern eine Aussage ueber die
     * Herkunft: Aus der Verpaarung zweier Traeger sind drei Viertel der
     * unauffaelligen Nachkommen Traeger — zwei Drittel bezogen auf die
     * wildtypisch aussehenden. Die Vererbungsrechnung braucht genau diese Zahl,
     * um aus einer unsicheren Angabe eine ehrliche Verteilung zu machen.
     */
    public function probability(): float
    {
        return match ($this) {
            self::Visual, self::Het => 1.0,
            self::PossHet66 => 2 / 3,
            self::PossHet50 => 0.5,
        };
    }
}
