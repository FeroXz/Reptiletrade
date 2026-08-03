<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

/**
 * Die sieben Schritte des Anzeigenassistenten. Der Fortschritt wird nicht
 * gespeichert, sondern aus dem Entwurf abgeleitet — so nimmt der Assistent
 * den Entwurf auch auf einem anderen Geraet an der richtigen Stelle wieder auf.
 */
enum WizardStep: int
{
    case TypUndArt = 1;
    case Merkmale = 2;
    case Details = 3;
    case Bilder = 4;
    case Nachweise = 5;
    case PreisUndStandort = 6;
    case Vorschau = 7;

    public function label(): string
    {
        return match ($this) {
            self::TypUndArt => 'Typ und Art',
            self::Merkmale => 'Merkmale',
            self::Details => 'Details',
            self::Bilder => 'Bilder',
            self::Nachweise => 'Rechtsnachweise',
            self::PreisUndStandort => 'Preis und Standort',
            self::Vorschau => 'Vorschau',
        };
    }

    public function next(): ?self
    {
        return self::tryFrom($this->value + 1);
    }

    public function previous(): ?self
    {
        return self::tryFrom($this->value - 1);
    }

    public function isBefore(self $other): bool
    {
        return $this->value < $other->value;
    }
}
