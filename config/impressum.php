<?php

declare(strict_types=1);

/**
 * Angaben für Impressum, Datenschutzerklärung und Kontakt.
 *
 * WICHTIG: Diese Datei muss der Betreiber ausfüllen. Ausgeliefert wird sie mit
 * Platzhaltern, und solange die drinstehen, zeigt die Seite einen sichtbaren
 * Warnhinweis statt eines fertig aussehenden Impressums. Ein unvollständiges
 * Impressum, das aussieht wie ein vollständiges, ist gefährlicher als gar
 * keines: Es fällt niemandem auf.
 *
 * `php bin/doctor.php` prüft die Datei mit.
 *
 * Rechtsgrundlagen (Deutschland, Stand 2026):
 *
 * - Anbieterkennzeichnung: § 5 DDG (seit Mai 2024 Nachfolger von § 5 TMG)
 * - Verantwortlicher für redaktionelle Inhalte: § 18 Abs. 2 MStV
 * - Datenschutz-Informationspflichten: Art. 13/14 DSGVO
 * - Verbraucherstreitbeilegung: § 36 VSBG
 * - Für gewerbliche Verkäufer gilt zusätzlich Widerrufsrecht und
 *   Preisangabenverordnung — die Plattform selbst verkauft nichts.
 *
 * Diese Hinweise sind eine Orientierung und keine Rechtsberatung. Ob und in
 * welchem Umfang die Pflichten greifen, hängt am konkreten Angebot; im Zweifel
 * gehört das vor der Freischaltung anwaltlich geprüft.
 */
return [
    /**
     * Solange dieser Wert true ist, weist jede Rechtsseite sichtbar darauf hin,
     * dass die Angaben noch fehlen. Nach dem Ausfüllen auf false setzen.
     */
    'unvollstaendig' => true,

    /**
     * Anbieter nach § 5 DDG. Bei Einzelunternehmen der volle Name, bei
     * Gesellschaften die Firma samt Rechtsform.
     */
    'anbieter' => [
        'name' => 'BITTE AUSFÜLLEN — Name oder Firma',
        'rechtsform' => '',
        'strasse' => 'BITTE AUSFÜLLEN — Straße und Hausnummer',
        'plz' => '00000',
        'ort' => 'BITTE AUSFÜLLEN — Ort',
        'land' => 'Deutschland',
        // Ein Postfach genügt nicht: § 5 DDG verlangt eine ladungsfähige
        // Anschrift.
        'vertreten_durch' => '',
    ],

    /**
     * Kontakt. E-Mail ist Pflicht, dazu ein zweiter Weg für unmittelbare
     * Kommunikation — Telefon oder ein Kontaktformular mit Antwortzusage.
     */
    'kontakt' => [
        'email' => 'BITTE AUSFÜLLEN — kontakt@deine-domain.tld',
        'telefon' => '',
        // Das Kontaktformular dieser Anwendung. Zählt als zweiter Weg, wenn
        // darauf verlässlich geantwortet wird.
        'formular' => '/kontakt',
    ],

    /**
     * Registereintrag, sofern vorhanden.
     */
    'register' => [
        'gericht' => '',
        'nummer' => '',
    ],

    /**
     * Umsatzsteuer-Identifikationsnummer nach § 27a UStG, sofern vorhanden.
     * Die Steuernummer gehört NICHT ins Impressum.
     */
    'umsatzsteuer_id' => '',

    /**
     * Verantwortlich für redaktionelle Inhalte nach § 18 Abs. 2 MStV.
     * Name und Anschrift; leer lassen, wenn identisch mit dem Anbieter.
     */
    'inhaltlich_verantwortlich' => '',

    /**
     * Aufsichtsbehörde, falls die Tätigkeit einer Zulassung bedarf.
     */
    'aufsichtsbehoerde' => '',

    /**
     * Verbraucherstreitbeilegung nach § 36 VSBG.
     *
     * "nicht_bereit" ist die übliche und zulässige Angabe, solange keine
     * gesetzliche Teilnahmepflicht besteht — sie muss aber dastehen.
     */
    'streitschlichtung' => [
        'bereit' => false,
        'stelle' => '',
    ],

    /**
     * Datenschutz. Ein benannter Datenschutzbeauftragter ist nur unter den
     * Voraussetzungen des Art. 37 DSGVO / § 38 BDSG Pflicht.
     */
    'datenschutz' => [
        'verantwortlicher' => '',       // leer = Anbieter oben
        'beauftragter' => '',
        'beauftragter_email' => '',
        // Zuständige Aufsichtsbehörde für die Beschwerde nach Art. 77 DSGVO.
        'aufsichtsbehoerde' => 'Die für deinen Sitz zuständige Landesdatenschutzbehörde',
    ],

    /**
     * Wo die Anwendung läuft — für die Datenschutzerklärung.
     */
    'hosting' => [
        'anbieter' => 'BITTE AUSFÜLLEN — Hoster',
        'ort' => 'Deutschland',
        // Ein Auftragsverarbeitungsvertrag nach Art. 28 DSGVO ist mit dem
        // Hoster abzuschliessen.
        'avv_geschlossen' => false,
    ],
];
