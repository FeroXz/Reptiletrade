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
    case SexLinked = 'sex_linked';

    public function label(): string
    {
        return match ($this) {
            self::Dominant => 'dominant',
            self::IncompleteDominant => 'unvollständig dominant',
            self::Recessive => 'rezessiv',
            self::Polygenic => 'polygen',
            self::LineBred => 'liniengezüchtet',
            self::Paradox => 'Paradox',
            self::SexLinked => 'geschlechtsgebunden',
        };
    }

    /**
     * Nur rezessive und geschlechtsgebundene Merkmale koennen als het/poss. het
     * gefuehrt werden.
     *
     * Beim geschlechtsgebundenen Erbgang gilt das nur fuer das homogametische
     * Geschlecht (bei Reptilien mit ZW-System das Maennchen): Es hat zwei
     * Geschlechtschromosomen und kann eine Anlage tragen, ohne sie zu zeigen.
     * Das andere Geschlecht ist hemizygot — was es traegt, sieht man ihm an.
     * Der Unterschied wird in der Vererbungsrechnung ausgewertet
     * (Domain\Genetics\SexLinkedRule); die Merkmalsauswahl einer Anzeige laesst
     * beide Angaben zu, weil dort das Geschlecht nicht zwingend feststeht.
     */
    public function allowsHeterozygous(): bool
    {
        return $this === self::Recessive || $this === self::SexLinked;
    }
}
