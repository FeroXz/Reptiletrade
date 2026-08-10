<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support\Demo;

/**
 * Wie der Volltextindex nach dem Erzeugen der Beispielanzeigen nachgezogen wird.
 */
enum DemoIndexStrategy
{
    /**
     * Nur die neu angelegten Anzeigen nachtragen. Langsamer je Anzeige, ruehrt
     * den vorhandenen Index aber nicht an — die einzige Wahl im laufenden Betrieb.
     */
    case Inkrementell;

    /**
     * Den gesamten Index verwerfen und neu aufbauen. Schneller bei grossen
     * Mengen, haelt dafuer eine lange Schreibsperre und ist deshalb nur fuer
     * Entwicklung und Messungen gedacht.
     */
    case Neuaufbau;
}
