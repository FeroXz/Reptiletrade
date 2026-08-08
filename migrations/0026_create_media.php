<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Die Mediathek der Redaktion — getrennt von listing_media.
     *
     * Zwei Tabellen, weil es zwei verschiedene Dinge sind: Ein Anzeigenbild
     * gehoert einer Anzeige, verschwindet mit ihr und wird nie
     * wiederverwendet. Ein Redaktionsbild steht auf mehreren Seiten, ueberlebt
     * jede einzelne davon und traegt eine Beschreibung, die zum Zusammenhang
     * passt. Eine gemeinsame Tabelle haette fuer beide Faelle die falschen
     * Loeschregeln.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE media (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                -- Deduplizierung: Dasselbe Bild zweimal hochgeladen ergibt
                -- einen Eintrag. Der Hash ist der des verarbeiteten WebP, nicht
                -- der der hochgeladenen Datei — sonst zaehlten zwei JPEGs mit
                -- unterschiedlicher Kompression als verschieden, obwohl daraus
                -- dasselbe Bild wird.
                sha256            TEXT    NOT NULL UNIQUE,
                path              TEXT    NOT NULL UNIQUE,
                original_filename TEXT    NOT NULL DEFAULT '',
                mime              TEXT    NOT NULL DEFAULT 'image/webp',
                width             INTEGER NOT NULL CHECK (width > 0),
                height            INTEGER NOT NULL CHECK (height > 0),
                byte_size         INTEGER NOT NULL CHECK (byte_size >= 0),
                -- alt_text steht hier als Vorschlag. Pflicht ist er beim
                -- Einbinden in einen Bildblock: Beim Hochladen weiss noch
                -- niemand, wofuer das Bild steht.
                alt_text          TEXT    NOT NULL DEFAULT '',
                caption           TEXT    NOT NULL DEFAULT '',
                uploaded_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
                created_at        TEXT    NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_media_neueste ON media(created_at DESC)');

        // Wo wird ein Medium verwendet?
        //
        // Ohne diese Tabelle loescht jemand ein Bild, das auf drei Seiten
        // steht, und merkt es nie — bis die drei Seiten Luecken haben.
        $pdo->exec(<<<'SQL'
            CREATE TABLE media_usages (
                media_id   INTEGER NOT NULL REFERENCES media(id) ON DELETE CASCADE,
                context    TEXT    NOT NULL CHECK (context IN ('content_block','entry_og','menu')),
                context_id INTEGER NOT NULL,
                PRIMARY KEY (media_id, context, context_id)
            ) WITHOUT ROWID
            SQL);

        // Die Gegenrichtung: "Welche Medien haengen an diesem Eintrag?" —
        // gebraucht, wenn ein Eintrag gespeichert wird und die Verwendungen
        // neu geschrieben werden.
        $pdo->exec('CREATE INDEX idx_media_usages_ziel ON media_usages(context, context_id)');

        // og_image_id kommt erst hier dazu, nicht schon in 0023: Bei
        // eingeschaltetem PRAGMA foreign_keys beantwortet SQLite jeden
        // Schreibvorgang auf eine Tabelle mit "no such table", solange die
        // Elterntabelle eines Fremdschluessels fehlt — auch bei NULL-Werten.
        //
        // ADD COLUMN mit REFERENCES ist erlaubt, solange der Vorgabewert NULL
        // ist. Genau das ist hier der Fall.
        $pdo->exec('ALTER TABLE content_entries ADD COLUMN og_image_id INTEGER REFERENCES media(id) ON DELETE SET NULL');
    }

    public function down(PDO $pdo): void
    {
        // DROP COLUMN braucht SQLite 3.35 — dieselbe Schwelle, ab der das
        // Projekt ohnehin laeuft.
        $pdo->exec('ALTER TABLE content_entries DROP COLUMN og_image_id');
        $pdo->exec('DROP TABLE IF EXISTS media_usages');
        $pdo->exec('DROP TABLE IF EXISTS media');
    }
};
