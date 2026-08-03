<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Moderation;

enum ReportReason: string
{
    case Betrug = 'betrug';
    case Tierschutz = 'tierschutz';
    case FalscheArt = 'falsche_art';
    case Spam = 'spam';
    case Sonstiges = 'sonstiges';

    public function label(): string
    {
        return match ($this) {
            self::Betrug => 'Betrugsverdacht',
            self::Tierschutz => 'Tierschutz',
            self::FalscheArt => 'Falsche Artangabe',
            self::Spam => 'Spam oder Werbung',
            self::Sonstiges => 'Sonstiges',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Betrug => 'Vorkasse verlangt, Konto wirkt gefälscht, Tier existiert vermutlich nicht.',
            self::Tierschutz => 'Zu junges oder zu leichtes Tier, erkennbar kranke Tiere, Versand angeboten.',
            self::FalscheArt => 'Art oder Merkmal stimmt nicht mit den Bildern überein.',
            self::Spam => 'Werbung, Wiederholungen, unpassender Inhalt.',
            self::Sonstiges => 'Bitte beschreibe kurz, worum es geht.',
        };
    }

    /**
     * Meldungen mit Tierschutz- oder Betrugsbezug kommen in der
     * Moderationsliste zuerst.
     */
    public function priority(): int
    {
        return match ($this) {
            self::Tierschutz => 0,
            self::Betrug => 1,
            self::FalscheArt => 2,
            self::Spam => 3,
            self::Sonstiges => 4,
        };
    }
}
