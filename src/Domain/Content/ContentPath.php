<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use DateTimeImmutable;

/**
 * Baut und normalisiert Inhaltspfade.
 *
 * Ein Pfad beginnt mit einem Schraegstrich und endet mit einem — dieselbe Form
 * wie /markt/ und /art/{slug}/ im Bestand. Wer das nicht durchhaelt, hat zwei
 * Adressen fuer denselben Inhalt und liefert doppelte Inhalte an Suchmaschinen
 * aus.
 */
final class ContentPath
{
    /**
     * Der Pfad einer Seite: der Pfad des Elternteils plus eigener Slug.
     */
    public static function forPage(string $slug, ?string $parentPath = null): string
    {
        $base = $parentPath === null || $parentPath === '' ? '/' : self::normalize($parentPath);

        return $base . $slug . '/';
    }

    /**
     * Der Pfad eines Beitrags: /news/{jahr}/{slug}/.
     *
     * Das Jahr stammt aus dem Veroeffentlichungszeitpunkt, solange es einen
     * gibt — sonst aus dem Anlagedatum. Ein Entwurf hat damit von Anfang an
     * einen Pfad, und die Vorschau braucht keinen Sonderfall.
     */
    public static function forPost(string $slug, ?DateTimeImmutable $publishedAt, DateTimeImmutable $createdAt): string
    {
        return '/news/' . ($publishedAt ?? $createdAt)->format('Y') . '/' . $slug . '/';
    }

    public static function forEntry(ContentEntry $entry, ?string $parentPath, DateTimeImmutable $fallback): string
    {
        return $entry->type === ContentType::Beitrag
            ? self::forPost($entry->slug, $entry->publishedAt, $entry->createdAt ?? $fallback)
            : self::forPage($entry->slug, $parentPath);
    }

    /**
     * Fuehrender und abschliessender Schraegstrich, keine leeren Segmente.
     *
     * "/a//b" und "a/b" ergeben beide "/a/b/". Die Wurzel bleibt "/".
     */
    public static function normalize(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            $segment = trim($segment);
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return $segments === [] ? '/' : '/' . implode('/', $segments) . '/';
    }

    /**
     * Das erste Segment — die Einheit, in der die Reservierungsliste denkt.
     */
    public static function firstSegment(string $path): string
    {
        $normalized = self::normalize($path);

        if ($normalized === '/') {
            return '';
        }

        $rest = substr($normalized, 1, -1);
        $slash = strpos($rest, '/');

        return $slash === false ? $rest : substr($rest, 0, $slash);
    }

    /**
     * @return list<string>
     */
    public static function segments(string $path): array
    {
        $normalized = self::normalize($path);

        if ($normalized === '/') {
            return [];
        }

        return explode('/', substr($normalized, 1, -1));
    }
}
