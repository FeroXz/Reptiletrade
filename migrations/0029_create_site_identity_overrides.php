<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Impressumsangaben, die die Verwaltung im Browser aendert.
     *
     * Dieselbe Aufteilung wie bei den Oberflaechentexten (ui_texts): Die
     * Tabelle traegt **Ueberschreibungen**, nicht die Angaben selbst. Der
     * ausgelieferte Stand bleibt in config/impressum.php — er gehoert zum
     * Deployment, wird mit ihm ausgerollt und ist die Fassung, auf die sich
     * jeder Eintrag einzeln zuruecksetzen laesst.
     *
     * Der Grund fuer diese Richtung ist derselbe wie dort: Eine Datei, die
     * beim Deployment mitgeht, ueberlebt einen verlorenen Datenbestand. Wer
     * die Angaben nur in die Datenbank schriebe, haette nach einem
     * Wiedereinspielen einer alten Sicherung ein Impressum von vorgestern —
     * und niemand merkt es, weil die Seite ja aussieht wie immer.
     *
     * Der Schluessel ist der Pfad in der Konfiguration mit Punkt getrennt,
     * z. B. "anbieter.name" oder "kontakt.email".
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE site_identity_overrides (
                field_key  TEXT    NOT NULL PRIMARY KEY CHECK (field_key <> ''),
                value      TEXT    NOT NULL,
                updated_at TEXT    NOT NULL,
                updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL
            ) WITHOUT ROWID
            SQL);

        // Die Verwaltungsansicht sortiert nach Aenderungsdatum; der
        // Primaerschluessel traegt das Lesen je Anfrage.
        $pdo->exec('CREATE INDEX idx_site_identity_updated ON site_identity_overrides(updated_at DESC)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS site_identity_overrides');
    }
};
