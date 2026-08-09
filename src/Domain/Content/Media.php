<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use DateTimeImmutable;

/**
 * Ein Bild in der Mediathek.
 *
 * Der Pfad ist der der grossen Fassung; die kleineren leiten sich daraus ab
 * (siehe variantPath). Ein zweites Feld je Groesse waere drei Felder, die
 * auseinanderlaufen koennen, fuer eine Information, die aus einer folgt.
 */
final readonly class Media
{
    /**
     * Die Kantenlaengen, in denen jedes Bild vorliegt. 400 fuer Miniaturen und
     * schmale Telefone, 800 fuer den Regelfall, 1600 fuer grosse Bildschirme
     * und Geraete mit doppelter Punktdichte.
     *
     * @var list<int>
     */
    public const array WIDTHS = [400, 800, 1600];

    public function __construct(
        public ?int $id,
        public string $sha256,
        public string $path,
        public int $width,
        public int $height,
        public int $byteSize,
        public string $originalFilename = '',
        public string $mime = 'image/webp',
        public string $altText = '',
        public string $caption = '',
        public ?int $uploadedBy = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    /**
     * Die oeffentliche Adresse der grossen Fassung.
     */
    public function url(): string
    {
        return '/media/' . $this->path;
    }

    public function variantUrl(int $edge): string
    {
        return '/media/' . self::variantPath($this->path, $edge);
    }

    /**
     * Das srcset-Attribut. Nur Groessen, die kleiner sind als das Original —
     * ein hochskaliertes Bild anzubieten kostet Bandbreite ohne Gewinn.
     */
    public function srcset(): string
    {
        $parts = [];

        foreach (self::WIDTHS as $edge) {
            if ($edge > $this->width && $edge !== self::WIDTHS[0]) {
                continue;
            }

            $parts[] = $this->variantUrl($edge) . ' ' . min($edge, $this->width) . 'w';
        }

        return implode(', ', $parts);
    }

    /**
     * Die Hoehe zur angezeigten Breite — fuer width/height im Markup, damit
     * beim Laden nichts springt.
     */
    public function heightFor(int $width): int
    {
        return $this->width === 0 ? 0 : (int) round($this->height * ($width / $this->width));
    }

    /**
     * Aus "2026/08/abc.webp" wird "2026/08/abc-800.webp". Die groesste Fassung
     * behaelt den Grundnamen: Sie ist das, worauf src zeigt, wenn ein Browser
     * mit srcset nichts anfangen kann.
     */
    public static function variantPath(string $path, int $edge): string
    {
        if ($edge === self::WIDTHS[array_key_last(self::WIDTHS)]) {
            return $path;
        }

        return preg_replace('/\.webp$/', '-' . $edge . '.webp', $path) ?? $path;
    }

    /**
     * Alle Dateien, die zu diesem Eintrag gehoeren — fuer das Loeschen.
     *
     * @return list<string>
     */
    public static function allPaths(string $path): array
    {
        $paths = [];

        foreach (self::WIDTHS as $edge) {
            $paths[] = self::variantPath($path, $edge);
        }

        return array_values(array_unique($paths));
    }
}
