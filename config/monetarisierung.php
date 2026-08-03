<?php

declare(strict_types=1);

/**
 * Monetarisierung — vorbereitet, nicht aktiviert.
 *
 * Solange "enabled" auf false steht, verhaelt sich die Plattform wie bisher:
 * keine Begrenzung der Anzeigenzahl, keine kostenpflichtigen Merkmale, kein
 * Zahlungsanbieter. Die Tabellen existieren trotzdem, die Preise stehen hier,
 * und die Schnittstellen sind verdrahtet und getestet — umgelegt wird ein
 * Schalter, nicht ein Umbau.
 *
 * Der Schalter laesst sich zusaetzlich zur Laufzeit ueber das Setting
 * "billing.enabled" setzen; das Setting sticht diese Datei, damit der Betreiber
 * ohne Deployment abschalten kann.
 */
return [
    /**
     * Der Hauptschalter. Vor dem Umlegen gehoeren AGB, Widerrufsbelehrung und
     * Preisangaben nach PAngV geprueft — das ist keine Codefrage.
     */
    'enabled' => false,

    'setting_key' => 'billing.enabled',

    /**
     * Waehrung je Land. Die Preise unten stehen in der jeweils kleinsten
     * Einheit (Cent beziehungsweise Rappen).
     */
    'currencies' => [
        'DE' => 'EUR',
        'AT' => 'EUR',
        'CH' => 'CHF',
    ],

    /**
     * Umsatzsteuer. Die Preise sind Bruttopreise — Verbraucher sehen den
     * Endpreis, alles andere waere nach PAngV angreifbar.
     */
    'tax' => [
        'included' => true,
        'rates' => ['DE' => 19.0, 'AT' => 20.0, 'CH' => 8.1],
    ],

    /**
     * Tarife. "frei" ist der Tarif ohne Vertrag; jedes Konto hat ihn, solange
     * kein Abo laeuft.
     */
    'plans' => [
        'frei' => [
            'name' => 'Kostenlos',
            'description' => 'Für alle, die gelegentlich ein Tier abgeben.',
            'price_cents' => 0,
            'interval' => 'keiner',
            'limits' => [
                // Aus dem Auftrag: drei aktive Anzeigen, 60 Tage Laufzeit.
                'aktive_anzeigen' => 3,
                'laufzeit_tage' => 60,
                'bilder_je_anzeige' => 12,
            ],
            'features' => [],
        ],
        'zuechter' => [
            'name' => 'Züchter',
            'description' => 'Unbegrenzt Anzeigen, öffentliches Profil, Statistiken, '
                . 'Ankündigungen für kommende Nachzuchten.',
            'price_cents' => 990,
            'interval' => 'monatlich',
            'limits' => [
                // null heisst unbegrenzt.
                'aktive_anzeigen' => null,
                'laufzeit_tage' => 90,
                'bilder_je_anzeige' => 24,
            ],
            'features' => ['profilseite', 'statistiken', 'nachzucht_ankuendigung'],
        ],
        'zuechter_jahr' => [
            'name' => 'Züchter, jährlich',
            'description' => 'Wie Züchter, zwei Monate günstiger.',
            'price_cents' => 9900,
            'interval' => 'jaehrlich',
            'limits' => [
                'aktive_anzeigen' => null,
                'laufzeit_tage' => 90,
                'bilder_je_anzeige' => 24,
            ],
            'features' => ['profilseite', 'statistiken', 'nachzucht_ankuendigung'],
        ],
    ],

    'default_plan' => 'frei',

    /**
     * Boosts: Top-Platzierung auf Zeit. Der Schluessel ist die Laufzeit in
     * Tagen, weil genau die den Preis bestimmt.
     */
    'boosts' => [
        'top_7' => ['name' => 'Top-Platzierung, 7 Tage', 'days' => 7, 'price_cents' => 490],
        'top_14' => ['name' => 'Top-Platzierung, 14 Tage', 'days' => 14, 'price_cents' => 790],
        'top_30' => ['name' => 'Top-Platzierung, 30 Tage', 'days' => 30, 'price_cents' => 1490],
    ],

    /**
     * Zahlungsanbieter. "keiner" ist die Voreinstellung und lehnt jeden
     * Zahlungsvorgang ab — passend dazu, dass die Monetarisierung aus ist.
     *
     * Kein Treuhandservice in Version 1: Die Plattform nimmt kein Geld fuer
     * Tierverkaeufe entgegen, nur fuer eigene Leistungen.
     */
    'provider' => [
        'name' => 'keiner',
        'stripe' => [
            // Schluessel gehoeren in die .env, nicht hierher.
            'secret_key_env' => 'STRIPE_SECRET_KEY',
            'webhook_secret_env' => 'STRIPE_WEBHOOK_SECRET',
            'api_base' => 'https://api.stripe.com/v1',
            'success_path' => '/konto/zahlung/erfolg',
            'cancel_path' => '/konto/zahlung/abbruch',
        ],
    ],
];
