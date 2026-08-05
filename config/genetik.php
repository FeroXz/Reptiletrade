<?php

declare(strict_types=1);

use Reptilienmarkt\Support\Env;

/**
 * Vererbungsrechnung (Phase 10).
 *
 * Was hier steht, laesst sich nicht aus dem Merkmalskatalog ableiten und
 * gehoert auch nicht in ihn:
 *
 * - Superformen. Dass "Silkback" die homozygote Leatherback ist, weiss der
 *   Katalog nicht — er sieht nur zwei Merkmale derselben Allelgruppe. Aus dem
 *   Namen laesst es sich nicht raten ("Super Snow" folgt einer Konvention,
 *   "Silkback" nicht), und geraten waere hier das Schlechteste: Aus einer
 *   falsch zugeordneten Superform wird eine falsche Verteilung.
 * - Geschlechtschromosomen. Ob eine Art ZW-, XY- oder gar kein System hat,
 *   entscheidet ueber die Rechnung bei geschlechtsgebundenen Merkmalen. Viele
 *   Reptilien bestimmen das Geschlecht ueber die Bruttemperatur und haben
 *   keine Geschlechtschromosomen — dort ergibt der Erbgang keinen Sinn.
 * - Gelegegroessen. Sie machen aus Prozentzahlen eine Aussage, mit der ein
 *   Zuechter planen kann: "aus etwa 18 Schluepflingen" statt "25 %".
 * - Tierschutzhinweise. Merkmale, deren Verdopplung oder Auspraegung dem Tier
 *   schadet. Der Simulator warnt, sobald sie in der Nachzucht auftreten
 *   koennen — nicht erst, wenn jemand sie anbietet.
 *
 * Angaben zu Gelegen sind Erfahrungswerte aus der Haltungsliteratur und
 * Naeherungen; sie schwanken mit Alter, Kondition und Saison des Muttertiers.
 */
return [
    /**
     * Hauptschalter. Das Setting "genetik.enabled" sticht diese Datei, damit
     * sich der Rechner ohne Deployment abschalten laesst.
     */
    'enabled' => Env::bool('GENETIK_SIMULATOR_ENABLED', true),

    'setting_key' => 'genetik.enabled',

    /**
     * Stufenweise Freischaltung. 100 bedeutet: fuer alle. Kleinere Werte geben
     * den Rechner einem festen Teil der Konten frei — die Zuordnung haengt an
     * der Konto-ID und wechselt deshalb nicht von Aufruf zu Aufruf.
     */
    'rollout_percentage' => Env::int('GENETIK_ROLLOUT_PROZENT', 100),

    /**
     * Obergrenze fuer die Kombinatorik. Jeder beteiligte Genort vervielfacht
     * die Zahl der Genotyp-Kombinationen; ab hier bricht die Rechnung mit einer
     * Meldung ab, statt die Anfrage minutenlang zu beschaeftigen.
     */
    'max_combinations' => 20000,

    /**
     * Voreinstellung fuer Arten ohne eigenen Eintrag.
     */
    'standard' => [
        'geschlechtssystem' => 'keines',
        'gelege' => ['groesse' => 10, 'schlupfquote' => 0.8],
        'superformen' => [],
        'tierschutz' => [],
        'letalkombinationen' => [],
    ],

    /**
     * Je Art, ueber den Art-Slug.
     *
     * "letalkombinationen" beschreibt Merkmalspaare, die zusammen nicht
     * lebensfaehig sind — anders als die homozygote Letalitaet eines einzelnen
     * Merkmals, die am Katalogeintrag steht (morphs.is_lethal_combo). Die Liste
     * ist bewusst leer: Fuer die hier gefuehrten Arten ist keine solche
     * Kombination belegt, und eine erfundene Warnung waere schlimmer als keine.
     * Der Aufbau eines Eintrags:
     *
     *     ['merkmale' => ['Merkmal A', 'Merkmal B'], 'anteil' => 1.0, 'hinweis' => '…']
     */
    'arten' => [
        'pogona-vitticeps' => [
            'geschlechtssystem' => 'zw',
            'gelege' => ['groesse' => 20, 'schlupfquote' => 0.8],
            'superformen' => [
                // Silkback ist die homozygote Leatherback, kein eigener Genort.
                'Silkback' => 'Leatherback',
            ],
            'tierschutz' => [
                'Silkback' => 'Silkbacks haben keine Schuppen. Gestörte Häutung, erhöhter Wasserverlust und '
                    . 'Lichtempfindlichkeit sind die Regel, nicht die Ausnahme — die Verpaarung zweier '
                    . 'Leatherbacks bringt sie planmäßig hervor.',
            ],
            'letalkombinationen' => [],
        ],

        'python-regius' => [
            'geschlechtssystem' => 'zw',
            'gelege' => ['groesse' => 6, 'schlupfquote' => 0.85],
            // Die homozygoten Formen des BEL-Komplexes (Mojave, Lesser, Butter)
            // tragen eigene Handelsnamen, stehen aber nicht im Katalog. Solange
            // sie fehlen, bleibt die homozygote Form unter dem Namen der
            // Basisform stehen — das ist richtiger als ein geratener Name.
            'superformen' => [],
            'tierschutz' => [
                'Spider' => 'Spider-Tiere zeigen eine neurologische Störung ("Wobble"), die sich nicht '
                    . 'herauszüchten lässt; sie gehört zum Merkmal. Die homozygote Form ist nicht lebensfähig.',
                'Champagne' => 'Champagne ist mit einer neurologischen Störung verbunden; die homozygote Form '
                    . 'ist nicht lebensfähig.',
                'Hidden Gene Woma' => 'Hidden Gene Woma zeigt eine neurologische Störung; die homozygote Form '
                    . 'ist nicht lebensfähig.',
            ],
            'letalkombinationen' => [],
        ],

        'eublepharis-macularius' => [
            // Der Leopardgecko bestimmt das Geschlecht ueber die
            // Bruttemperatur — es gibt keine Geschlechtschromosomen.
            'geschlechtssystem' => 'keines',
            'gelege' => ['groesse' => 2, 'schlupfquote' => 0.85],
            'superformen' => [
                'Super Snow' => 'Mack Snow',
            ],
            'tierschutz' => [
                'Enigma' => 'Enigma-Tiere zeigen das "Enigma-Syndrom": Gleichgewichtsstörungen, Kreiseln, '
                    . 'Sternengucken. Das Merkmal und die Störung lassen sich nicht trennen.',
                'Lemon Frost' => 'Lemon Frost ist mit Iridophoromen verbunden — Hauttumoren, die im Laufe des '
                    . 'Lebens auftreten.',
            ],
            'letalkombinationen' => [],
        ],

        'correlophus-ciliatus' => [
            'geschlechtssystem' => 'keines',
            'gelege' => ['groesse' => 2, 'schlupfquote' => 0.8],
            'superformen' => [],
            'tierschutz' => [
                'Lilly White' => 'Die homozygote Lilly White ist nicht lebensfähig. Aus der Verpaarung zweier '
                    . 'Lilly Whites entwickelt sich ein Viertel der Eier nicht.',
            ],
            'letalkombinationen' => [],
        ],
    ],
];
