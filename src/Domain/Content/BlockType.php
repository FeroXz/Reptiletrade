<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

/**
 * Das Blockvokabular der ersten Fassung.
 *
 * Kein Roh-HTML: Ein solcher Block verlangte entweder 'unsafe-inline' in der
 * Content-Security-Policy oder einen Sanitizer, der jedem neuen Browser-Trick
 * hinterherlaeuft. Kommt er spaeter, dann als eigener Typ, nur fuer die
 * Verwaltung und mit Vermerk im Audit-Trail.
 */
enum BlockType: string
{
    case Text = 'text';
    case Bild = 'bild';
    case Galerie = 'galerie';
    case Zitat = 'zitat';
    case Trenner = 'trenner';
    case Hinweis = 'hinweis';
    case Cta = 'cta';
    case AnzeigenTeaser = 'anzeigen-teaser';
    case ArtenTeaser = 'arten-teaser';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Bild => 'Bild',
            self::Galerie => 'Galerie',
            self::Zitat => 'Zitat',
            self::Trenner => 'Trenner',
            self::Hinweis => 'Hinweis',
            self::Cta => 'Handlungsaufruf',
            self::AnzeigenTeaser => 'Anzeigen-Teaser',
            self::ArtenTeaser => 'Arten-Teaser',
        };
    }

    /**
     * Traegt der Block Text, der in die Volltextsuche gehoert?
     */
    public function isSearchable(): bool
    {
        return match ($this) {
            self::Text, self::Zitat, self::Hinweis, self::Cta => true,
            default => false,
        };
    }

    /**
     * Bindet der Block Medien ein? Entscheidet darueber, ob beim Speichern
     * Verwendungen in media_usages geschrieben werden.
     */
    public function usesMedia(): bool
    {
        return $this === self::Bild || $this === self::Galerie;
    }

    /**
     * Die Auswahl im Editor — in der Reihenfolge, in der sie angeboten wird.
     *
     * @return list<self>
     */
    public static function choices(): array
    {
        return self::cases();
    }
}
