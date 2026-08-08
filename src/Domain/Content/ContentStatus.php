<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

enum ContentStatus: string
{
    case Entwurf = 'entwurf';
    case Geplant = 'geplant';
    case Veroeffentlicht = 'veroeffentlicht';
    case Archiviert = 'archiviert';

    public function label(): string
    {
        return match ($this) {
            self::Entwurf => 'Entwurf',
            self::Geplant => 'Geplant',
            self::Veroeffentlicht => 'Veröffentlicht',
            self::Archiviert => 'Archiviert',
        };
    }

    /**
     * Oeffentlich abrufbar? Geplantes ist es noch nicht — auch dann nicht, wenn
     * der Termin gerade verstrichen ist. Freigeschaltet wird ueber den Auftrag
     * content.publish, damit eine Seite nicht davon abhaengt, ob zufaellig
     * jemand vorbeikommt.
     */
    public function isPublic(): bool
    {
        return $this === self::Veroeffentlicht;
    }

    /**
     * Ein bewusst entfernter Inhalt ist etwas anderes als ein Tippfehler in der
     * URL — deshalb 410 statt 404, wenn keine Weiterleitung existiert.
     */
    public function isGone(): bool
    {
        return $this === self::Archiviert;
    }

    /**
     * Braucht dieser Status einen Veroeffentlichungszeitpunkt? Die Datenbank
     * setzt dasselbe als CHECK durch.
     */
    public function requiresPublishedAt(): bool
    {
        return $this === self::Geplant || $this === self::Veroeffentlicht;
    }
}
