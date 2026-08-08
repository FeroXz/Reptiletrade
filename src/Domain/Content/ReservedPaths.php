<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

/**
 * Pfadsegmente, die die Anwendung selbst belegt.
 *
 * Die Catch-all-Route steht als letzte im Router — eine Seite mit dem Slug
 * "markt" wuerde also nie ausgeliefert, weil /markt/ vorher greift. Das waere
 * ein stiller Fehler: Der Redakteur speichert, bekommt eine Bestaetigung, und
 * die Seite bleibt unsichtbar. Deshalb wird beim Speichern geprueft, und die
 * Meldung nennt den Konflikt beim Namen.
 *
 * Damit die Liste nicht auslaeuft, sobald jemand eine Route ergaenzt, vergleicht
 * tests/Http/ReservedPathsTest sie gegen die tatsaechlich registrierten Muster
 * aus config/routes.php.
 *
 * Die Rechtsseiten stehen ausdruecklich mit darauf: /impressum, /datenschutz
 * und /nutzungsbedingungen speisen sich aus config/impressum.php und gehoeren
 * in eine Datei, die beim Deployment mitgeht — nicht in eine Datenbanktabelle,
 * aus der ein Entwurf sie versehentlich verdraengen koennte.
 */
final class ReservedPaths
{
    /**
     * Erste Segmente, die belegt sind.
     *
     * @var list<string>
     */
    private const array SEGMENTS = [
        'admin',
        'anmelden',
        'abmelden',
        'anzeige',
        'api',
        'art',
        'assets',
        'datenschutz',
        'feed.xml',
        'impressum',
        'kontakt',
        'konto',
        'markt',
        'media',
        'meine-anzeigen',
        'melden',
        'moderation',
        'nachweis',
        'news',
        'nutzungsbedingungen',
        'paarung',
        'passwort',
        'postfach',
        'registrieren',
        'robots.txt',
        'sitemap.xml',
        'tarife',
        'uploads',
        'vorschau',
        'zuechter',
    ];

    /**
     * Zusaetzlich gesperrte Slugs, die kein Routensegment sind: Sie wuerden
     * Dateien im Webroot verdecken oder aus einem Pfad ausbrechen.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_SLUGS = ['.', '..', 'index.php', 'favicon.ico'];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::SEGMENTS;
    }

    /**
     * Belegt die Anwendung diesen Pfad bereits?
     */
    public static function isReserved(string $path): bool
    {
        $segment = ContentPath::firstSegment($path);

        // Die Startseite gehoert dem Marktplatz.
        if ($segment === '') {
            return true;
        }

        return \in_array(strtolower($segment), self::SEGMENTS, true);
    }

    public static function isForbiddenSlug(string $slug): bool
    {
        return \in_array(strtolower(trim($slug)), self::FORBIDDEN_SLUGS, true);
    }

    /**
     * Prueft einen Pfad und wirft mit einer Meldung, die den Konflikt benennt.
     *
     * Beitraege sind ausgenommen: Sie liegen bewusst unter /news/, und /news/
     * steht auf der Liste.
     *
     * @throws ContentException
     */
    public static function guard(string $path, ContentType $type): void
    {
        if ($type === ContentType::Beitrag) {
            return;
        }

        if (!self::isReserved($path)) {
            return;
        }

        $segment = ContentPath::firstSegment($path);

        throw new ContentException(\sprintf(
            'Der Pfad "%s" ist belegt: "%s" gehört bereits zu einem festen Bereich der Anwendung. Wähle einen anderen Slug.',
            ContentPath::normalize($path),
            $segment === '' ? '/' : '/' . $segment . '/',
        ));
    }
}
