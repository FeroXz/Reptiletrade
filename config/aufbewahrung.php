<?php

declare(strict_types=1);

/**
 * Aufbewahrungsfristen.
 *
 * Getrennte Fristen, weil die Gruende verschieden sind: Nachrichten sind
 * Kommunikationsdaten und gehoeren geloescht, sobald der Zweck erfuellt ist.
 * Rechtsnachweise unterliegen dagegen Nachweispflichten und muessen laenger
 * liegenbleiben — eine gemeinsame Frist waere fuer das eine zu lang und fuer
 * das andere zu kurz.
 *
 * Alle Werte in Tagen. 0 schaltet die jeweilige Loeschung ab.
 *
 * Die Zahlen sind Vorschlaege, keine Rechtsauskunft. Was tatsaechlich gilt,
 * haengt vom Sitz des Betreibers und von der Art der Daten ab; das gehoert vor
 * dem Betrieb geprueft.
 */
return [
    /**
     * Nachrichten und Gespraeche. Nach dieser Zeit ohne neue Nachricht wird
     * das Gespraech geloescht.
     */
    'nachrichten_tage' => 730,

    /**
     * Rechtsnachweise am Listing (EU-Bescheinigung, Herkunftsnachweis,
     * Meldung). Laenger als alles andere, weil sie im Streitfall die
     * Rechtmaessigkeit der Abgabe belegen.
     */
    'rechtsnachweise_tage' => 3650,

    /**
     * Identitaets- und Gewerbenachweise am Konto. Kurz: Nach der Pruefung ist
     * ihr Zweck erfuellt, und ein Ausweisscan ist das Letzte, was man
     * aufbewahren moechte.
     */
    'identitaetsnachweise_tage' => 30,

    /**
     * Abgelaufene und archivierte Anzeigen.
     */
    'anzeigen_tage' => 365,

    /**
     * Erledigte Auftraege in der Job-Tabelle. Gescheiterte bleiben liegen —
     * sie warten auf einen Menschen.
     */
    'jobs_tage' => 30,

    /**
     * Rate-Limit-Treffer. Nur so lange, wie das laengste Fenster reicht.
     */
    'rate_limits_tage' => 2,

    /**
     * Verbrauchte und abgelaufene Einmal-Token.
     */
    'token_tage' => 30,

    /**
     * Abgemeldete und abgelaufene Sitzungen.
     */
    'sitzungen_tage' => 30,

    /**
     * Anwendungsprotokoll. Der Audit-Trail faellt ausdruecklich nicht
     * darunter: Er ist revisionssicher und wird nie automatisch geloescht.
     */
    'protokoll_tage' => 90,

    /**
     * Wann vor dem Ablauf einer Anzeige erinnert wird.
     */
    'ablauf_erinnerung_tage' => [7, 1],

    /**
     * Fassungen je redaktionellem Eintrag. Anders als alles darueber eine
     * ANZAHL, keine Frist: Was zaehlt, ist "die letzten dreissig Staende", nicht
     * "die Staende der letzten dreissig Tage" — ein Text, an dem ein halbes
     * Jahr niemand gearbeitet hat, soll seine Vorgeschichte behalten.
     *
     * Mindestens 1. Eine 0 waere kein Abschalten, sondern der Verlust jeder
     * Rueckkehrmoeglichkeit; wer keine Fassungen will, bekommt sie trotzdem —
     * sie kosten wenig und retten viel.
     */
    'inhalt_fassungen_je_eintrag' => 30,

    /**
     * Abgelaufene Vorschaulinks.
     */
    'vorschau_tage' => 2,
];
