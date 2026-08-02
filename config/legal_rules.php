<?php

declare(strict_types=1);

/*
 * Regelwerk der Rechts-Engine.
 *
 * KEINE RECHTSBERATUNG. Diese Datei bildet ab, was der Betreiber als Regelwerk
 * festgelegt hat — nicht, was im Einzelfall rechtlich gilt. Artenschutz- und
 * Tierschutzrecht aendern sich laufend; die Pflicht zur Pflege dieser Datei und
 * der Tabelle legal_texts liegt beim Betreiber.
 *
 * Die Reihenfolge der Regeln bestimmt die Auswertungsreihenfolge. Jede Regel
 * laesst sich einzeln ueber "enabled" abschalten.
 */

return [
    // Ab wann das Admin-Dashboard einen Rechtstext als ueberfaellig meldet.
    'review_max_age_months' => 12,

    'rules' => [
        // Regel 1 — Anhang A und streng geschuetzte Arten.
        // Vermarktungsbescheinigung mit Referenznummer Pflicht, immer Admin-Freigabe.
        'anhang_a' => [
            'enabled' => true,
            'annexes' => ['A'],
            'bnatschg_statuses' => ['streng'],
            'required_fields' => [
                'legal_docs.eu_bescheinigung',
                'legal_docs.eu_bescheinigung.reference_number',
            ],
            'text_key' => 'legal.anhang_a',
        ],

        // Regel 2 — Anhang B. Herkunftsnachweis Pflicht.
        // Anhang A ist ausgenommen, dort greift die Bescheinigung aus Regel 1.
        'anhang_b' => [
            'enabled' => true,
            'annexes' => ['B'],
            'excluded_annexes' => ['A'],
            'include_doku_pflicht' => true,
            'block_when_missing' => true,
            'required_fields' => ['legal_docs.herkunftsnachweis'],
            'text_key' => 'legal.anhang_b',
        ],

        // Regel 3 — meldepflichtige Arten (§ 7 BArtSchV).
        'meldepflicht' => [
            'enabled' => true,
            'required_fields' => ['meldung_bestaetigt', 'meldung_datum'],
            'confirmation_field' => 'meldung_bestaetigt',
            'date_field' => 'meldung_datum',
            'text_key' => 'legal.meldepflicht',
        ],

        // Regel 4 — Kennzeichnung bei Landschildkroeten.
        'kennzeichnung' => [
            'enabled' => true,
            'genera' => ['Testudo'],
            'allowed_methods' => ['transponder', 'fotodokumentation'],
            'methods_needing_code' => ['transponder'],
            'method_field' => 'kennzeichnung_art',
            'code_field' => 'kennzeichnung_nummer',
            'text_key' => 'legal.kennzeichnung',
        ],

        // Regel 5 — Gefahrtiere.
        //
        // OFFEN: Die Zuordnung Bundesland/Kanton -> Modus ist bewusst leer.
        // Gefahrtierverordnungen sind Landes- bzw. Kantonsrecht und weichen stark
        // voneinander ab; sie gehoeren vom Betreiber gepflegt. Solange "regions"
        // leer ist, greift default_mode fuer alle Regionen.
        //
        // Modi: "sperre" (Veroeffentlichung blockiert), "warnung" (Hinweis),
        // "keine" (keine Verordnung, nur Vermerk im Audit-Trail).
        //
        // Beispiel:
        //   'regions' => [
        //       'DE' => ['Bayern' => 'sperre', 'Berlin' => 'warnung'],
        //       'CH' => ['ZH' => 'sperre'],
        //   ],
        'gefahrtier' => [
            'enabled' => true,
            'regions' => [],
            'default_mode' => 'warnung',
            'review_on_warning' => false,
            'setting_key' => 'legal.gefahrtier_enforcement',
            'text_key' => 'legal.gefahrtier',
        ],

        // Regel 6 — Tierschutz: Mindestabgabealter und -gewicht aus dem Artenstamm.
        'tierschutz' => [
            'enabled' => true,
            'require_data' => true,
            'hatch_date_field' => 'hatch_date',
            'weight_field' => 'weight_g',
            'text_key' => 'legal.tierschutz_abgabe',
        ],

        // Regel 7 — Versand. Zertifizierter Tiertransport, niemals Paketdienst.
        'versand' => [
            'enabled' => true,
            'allowed_countries' => ['DE', 'AT', 'CH'],
            'text_key' => 'legal.tiertransport',
        ],

        // Regel 8 — Gewerblichkeit.
        // Die Schwellwerte sind eine Betreiber-Heuristik ohne gesetzliche Grundlage.
        'gewerblichkeit' => [
            'enabled' => true,
            'active_listing_threshold' => 10,
            'sales_per_year_threshold' => 25,
            'required_fields' => ['erlaubnis_11_number', 'imprint'],
            'text_key' => 'legal.gewerblichkeit',
        ],
    ],
];
