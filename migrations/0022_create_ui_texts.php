<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Oberflaechentexte, die die Verwaltung aendern kann.
     *
     * Die Tabelle traegt **Ueberschreibungen**, nicht den Katalog. Die
     * Grundtexte bleiben in lang/de-DE.php: Sie gehoeren zum Stand der
     * Anwendung, werden mit ihr ausgeliefert und mit ihr geprueft (der
     * Uebersetzungstest vergleicht Templates gegen den Katalog). Wer sie in die
     * Datenbank verschoebe, haette nach dem naechsten Deployment einen Katalog,
     * der zur Oberflaeche nicht mehr passt, und keinen Test, der es merkt.
     *
     * Was hier steht, ist die Entscheidung des Betreibers: "An dieser Stelle
     * soll etwas anderes stehen." Genau deshalb laesst sich jeder Eintrag
     * einzeln zuruecknehmen — dann gilt wieder der ausgelieferte Text.
     *
     * Der Schluessel ist (locale, text_key): Derselbe Text kann je Sprache
     * anders ueberschrieben sein.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE ui_texts (
                locale     TEXT    NOT NULL,
                text_key   TEXT    NOT NULL,
                value      TEXT    NOT NULL,
                updated_at TEXT    NOT NULL,
                updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
                PRIMARY KEY (locale, text_key)
            )
            SQL);

        // Der Uebersetzer liest je Anfrage alle Ueberschreibungen einer
        // Sprache auf einmal — dafuer genuegt der Primaerschluessel. Der Index
        // hier dient der Verwaltungsansicht, die nach Aenderungsdatum sortiert.
        $pdo->exec('CREATE INDEX idx_ui_texts_updated ON ui_texts(updated_at DESC)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS ui_texts');
    }
};
