<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Seo;

/**
 * Die Adressen, die nicht aus dem Redaktionssystem kommen.
 *
 * Eine eigene Ablage statt vier erweiterter Repositorien: Was die Sitemap
 * braucht, ist je Bereich genau ein Pfad und ein Zeitstempel — dafuer die
 * Entitaeten aufzublaehen, die davon nichts wissen wollen, waere der teurere
 * Weg.
 */
interface SitemapRepository
{
    /**
     * Oeffentlich sichtbare Anzeigen.
     *
     * Entwuerfe, Anzeigen in Pruefung, pausierte, abgelaufene und gesperrte
     * bleiben draussen: Die Sitemap ist eine Einladung, und eine Einladung auf
     * eine Seite, die 404 antwortet, ist ein gemeldeter Fehler.
     *
     * @return list<SitemapUrl>
     */
    public function listings(int $limit = 20000): array;

    /**
     * @return list<SitemapUrl>
     */
    public function species(int $limit = 5000): array;

    /**
     * Oeffentliche Zuechterseiten aktiver Konten.
     *
     * @return list<SitemapUrl>
     */
    public function breeders(int $limit = 5000): array;
}
