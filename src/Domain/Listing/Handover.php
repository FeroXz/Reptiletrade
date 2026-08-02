<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

/**
 * Uebergabeart. Ein Versand ueber Paketdienste ist ausgeschlossen; "tiertransport"
 * meint ausschliesslich zertifizierten Tiertransport (Regel 7 der Rechts-Engine).
 */
enum Handover: string
{
    case Abholung = 'abholung';
    case UebergabeBoerse = 'uebergabe_boerse';
    case Tiertransport = 'tiertransport';

    public function label(): string
    {
        return match ($this) {
            self::Abholung => 'Abholung',
            self::UebergabeBoerse => 'Übergabe auf Börse',
            self::Tiertransport => 'Zertifizierter Tiertransport',
        };
    }
}
