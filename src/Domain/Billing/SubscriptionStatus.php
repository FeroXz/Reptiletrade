<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

enum SubscriptionStatus: string
{
    case Aktiv = 'aktiv';
    case Gekuendigt = 'gekuendigt';
    case Abgelaufen = 'abgelaufen';
    case ZahlungOffen = 'zahlung_offen';

    public function label(): string
    {
        return match ($this) {
            self::Aktiv => 'Aktiv',
            self::Gekuendigt => 'Gekündigt, läuft noch',
            self::Abgelaufen => 'Abgelaufen',
            self::ZahlungOffen => 'Zahlung ausstehend',
        };
    }

    /**
     * Zaehlt das Abo als laufend? Eine gekuendigte Mitgliedschaft laeuft bis
     * zum Periodenende weiter — bezahlt ist bezahlt.
     */
    public function grantsAccess(): bool
    {
        return $this === self::Aktiv || $this === self::Gekuendigt;
    }
}
