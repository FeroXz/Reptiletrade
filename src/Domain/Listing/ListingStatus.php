<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

enum ListingStatus: string
{
    case Entwurf = 'entwurf';
    case Pruefung = 'pruefung';
    case Aktiv = 'aktiv';
    case Reserviert = 'reserviert';
    case Pausiert = 'pausiert';
    case Verkauft = 'verkauft';
    case Abgelaufen = 'abgelaufen';
    case Gesperrt = 'gesperrt';

    public function label(): string
    {
        return match ($this) {
            self::Entwurf => 'Entwurf',
            self::Pruefung => 'In Prüfung',
            self::Aktiv => 'Aktiv',
            self::Reserviert => 'Reserviert',
            self::Pausiert => 'Pausiert',
            self::Verkauft => 'Verkauft',
            self::Abgelaufen => 'Abgelaufen',
            self::Gesperrt => 'Gesperrt',
        };
    }

    /**
     * Oeffentlich sichtbar in Suche und Listen.
     */
    public function isPubliclyVisible(): bool
    {
        return $this === self::Aktiv || $this === self::Reserviert;
    }

    /**
     * Laesst sich der Inhalt noch aendern?
     *
     * Nicht mehr, wenn die Anzeige abgeschlossen ist: Eine verkaufte Anzeige
     * nachtraeglich umzuschreiben wuerde die Bewertung des Handels auf einen
     * anderen Text zeigen lassen. Eine gesperrte bleibt ebenfalls unangetastet —
     * ueber sie entscheidet die Moderation, nicht der Anbieter.
     */
    public function isEditable(): bool
    {
        return \in_array($this, [self::Entwurf, self::Pruefung, self::Aktiv, self::Reserviert, self::Pausiert], true);
    }

    /**
     * Nur was oeffentlich steht, laesst sich anhalten. Ein Entwurf ist noch
     * nirgends zu sehen, eine gesperrte Anzeige schon nicht mehr.
     */
    public function isPausable(): bool
    {
        return $this === self::Aktiv || $this === self::Reserviert;
    }
}
