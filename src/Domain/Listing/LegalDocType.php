<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

/**
 * Arten von Rechtsnachweisen an einer Anzeige. Die zugehoerigen Dateien liegen
 * ausserhalb des Webroots und werden nur ueber einen authentifizierten Controller
 * ausgeliefert.
 */
enum LegalDocType: string
{
    case Herkunftsnachweis = 'herkunftsnachweis';
    case EuBescheinigung = 'eu_bescheinigung';
    case Meldung = 'meldung';
    case Elterntier = 'elterntier';
    case ErlaubnisParagraf11 = 'erlaubnis_11_tierschg';

    public function label(): string
    {
        return match ($this) {
            self::Herkunftsnachweis => 'Herkunftsnachweis',
            self::EuBescheinigung => 'EU-Vermarktungsbescheinigung',
            self::Meldung => 'Meldung bei der zuständigen Behörde',
            self::Elterntier => 'Elterntiernachweis',
            self::ErlaubnisParagraf11 => 'Erlaubnis nach § 11 TierSchG',
        };
    }

    /**
     * Nachweise, die eine amtliche Referenznummer tragen muessen.
     */
    public function requiresReferenceNumber(): bool
    {
        return $this === self::EuBescheinigung || $this === self::ErlaubnisParagraf11;
    }
}
