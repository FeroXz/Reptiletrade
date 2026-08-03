<?php

declare(strict_types=1);

/**
 * Vertrauen und Missbrauchsabwehr — Schwellwerte und Wortlisten als
 * Konfiguration, nicht im Code. Wie beim Regelwerk der Rechts-Engine gilt:
 * Ein Tippfehler hier fuehrt beim Aufbau zu einem Fehler und nicht zu einer
 * stillschweigend uebersprungenen Pruefung.
 */
return [
    /**
     * Kontaktmaskierung. In den ersten Nachrichten einer Konversation werden
     * E-Mail-Adressen und Telefonnummern unkenntlich gemacht, damit sich
     * Adressbestaende nicht durch massenhaftes Anschreiben einsammeln lassen.
     */
    'kontaktmaskierung' => [
        'enabled' => true,
        // Ab der vierten Nachricht ist ein echter Austausch im Gange.
        'erste_n_nachrichten' => 3,
    ],

    /**
     * Rate-Limits: [Anzahl, Fenster in Sekunden]. Gezaehlt wird ueber ein
     * gleitendes Fenster.
     */
    'rate_limits' => [
        'nachricht.konto' => ['limit' => 20, 'fenster' => 3600],
        'nachricht.ip' => ['limit' => 40, 'fenster' => 3600],
        'konversation.konto' => ['limit' => 10, 'fenster' => 3600],
        'anzeige.konto' => ['limit' => 20, 'fenster' => 86400],
        'meldung.konto' => ['limit' => 10, 'fenster' => 3600],
        'meldung.ip' => ['limit' => 20, 'fenster' => 3600],
        'registrierung.ip' => ['limit' => 5, 'fenster' => 3600],
        'anmeldung.ip' => ['limit' => 30, 'fenster' => 900],
        'passwort_reset.ip' => ['limit' => 5, 'fenster' => 3600],
        'verifizierung.konto' => ['limit' => 5, 'fenster' => 3600],
    ],

    /**
     * Auto-Moderation neuer Konten: Die ersten Anzeigen gehen in die
     * Pruefung statt direkt online.
     */
    'auto_moderation' => [
        'enabled' => true,
        'erste_n_anzeigen' => 3,
        // Konten, die laenger dabei sind und schon veroeffentlicht haben,
        // fallen nicht zurueck in die Pruefung.
        'gilt_bis_kontoalter_tage' => 30,
    ],

    /**
     * Betrugs-Keywords. Jeder Eintrag ist ein Muster mit Begruendung und
     * Gewicht. "sperre" haelt die Nachricht zurueck, "markieren" laesst sie
     * durch und legt sie der Moderation vor.
     *
     * Die Muster sind absichtlich als Wortlisten formuliert und nicht als
     * regulaere Ausdruecke: Wer sie pflegt, muss keine Regex schreiben koennen.
     * Woerter matchen an Wortgrenzen, Umlaute und Schreibvarianten gehoeren
     * als eigene Eintraege in die Liste.
     */
    'keywords' => [
        'vorkasse' => [
            'mode' => 'markieren',
            'reason' => 'Zahlung im Voraus ohne Uebergabe',
            'words' => [
                'vorkasse', 'vorab bezahlen', 'zahlung vorab', 'anzahlung per',
                'erst zahlen', 'geld zuerst', 'zahlung im voraus',
            ],
        ],
        'unsichere_zahlung' => [
            'mode' => 'sperre',
            'reason' => 'Zahlungsweg ohne Rueckholmoeglichkeit',
            'words' => [
                'western union', 'moneygram', 'ria money', 'paysafecard',
                'google play karte', 'amazon gutschein', 'steam guthaben',
                'bitcoin', 'krypto überweisung', 'paypal freunde',
                'paypal familie', 'freunde und familie',
            ],
        ],
        'versand' => [
            'mode' => 'markieren',
            'reason' => 'Tierversand ueber Paketdienste',
            'words' => [
                'tier wird versendet', 'versand des tieres', 'per dhl',
                'per hermes', 'mit der post verschicken', 'paketversand tier',
                'verschicke das tier', 'versand möglich', 'versand moeglich',
            ],
        ],
        'kanalwechsel' => [
            'mode' => 'markieren',
            'reason' => 'Draengen aus dem internen Postfach heraus',
            'words' => [
                'nur über whatsapp', 'nur ueber whatsapp', 'schreib mir auf whatsapp',
                'telegram schreiben', 'nur per telegram', 'schreib mir privat',
                'außerhalb der plattform', 'ausserhalb der plattform',
            ],
        ],
        'notlage' => [
            'mode' => 'markieren',
            'reason' => 'Zeitdruck als Verkaufsmittel',
            'words' => [
                'muss heute noch', 'nur heute verfügbar', 'letzte chance',
                'schnell entscheiden', 'sofort überweisen', 'sofort ueberweisen',
            ],
        ],
    ],

    /**
     * Ab wie vielen Treffern eine Nachricht der Moderation vorgelegt wird,
     * auch wenn keiner davon sperrt.
     */
    'markierung_ab_treffern' => 1,
];
