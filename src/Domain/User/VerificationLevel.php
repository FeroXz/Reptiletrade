<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

/**
 * Die Verifizierungsstufen bauen aufeinander auf: Wer den Ausweis geprueft hat,
 * hat vorher E-Mail und Telefon bestaetigt. Deshalb eine Leiter und kein Satz
 * unabhaengiger Merkmale.
 */
enum VerificationLevel: int
{
    case Keine = 0;
    case Email = 1;
    case Telefon = 2;
    case Identitaet = 3;

    public function label(): string
    {
        return match ($this) {
            self::Keine => 'Nicht bestätigt',
            self::Email => 'E-Mail bestätigt',
            self::Telefon => 'Telefon bestätigt',
            self::Identitaet => 'Identität geprüft',
        };
    }

    /**
     * Kurzform fuer das Abzeichen an Profil und Anzeige.
     */
    public function badge(): string
    {
        return match ($this) {
            self::Keine => 'neu',
            self::Email => 'E-Mail',
            self::Telefon => 'Telefon',
            self::Identitaet => 'geprüft',
        };
    }

    public function atLeast(self $required): bool
    {
        return $this->value >= $required->value;
    }

    public function next(): ?self
    {
        return self::tryFrom($this->value + 1);
    }
}
