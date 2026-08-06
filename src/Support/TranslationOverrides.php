<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support;

/**
 * Texte, die den ausgelieferten Sprachkatalog ueberschreiben.
 *
 * Das Interface steht bewusst hier und nicht in der Domain: Der Uebersetzer
 * gehoert zum Unterbau und darf nichts aus den Fachbereichen kennen. Wo die
 * Ueberschreibungen herkommen — Datenbank, Datei, gar nirgendwo — geht ihn
 * nichts an.
 */
interface TranslationOverrides
{
    /**
     * Alle Ueberschreibungen einer Sprache.
     *
     * Alle auf einmal und nicht je Schluessel: Eine Seite fragt Dutzende
     * Texte ab, und Dutzende Abfragen je Seitenaufruf waeren der Preis fuer
     * nichts. Implementierungen speichern das Ergebnis deshalb zwischen — und
     * verwerfen den Zwischenspeicher, sobald sich eine Ueberschreibung
     * aendert.
     *
     * @return array<string, string>
     */
    public function forLocale(string $locale): array;
}
