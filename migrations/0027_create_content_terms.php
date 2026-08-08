<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Kategorien, Schlagwoerter und der Volltextindex der Inhalte.
     *
     * Eine Tabelle fuer beide Ordnungen mit einer taxonomy-Spalte, aus
     * demselben Grund wie bei content_entries: Die Felder sind identisch, und
     * zwei Tabellen hiessen jede Abfrage und jede Zuordnung doppelt zu bauen.
     * Was sich unterscheidet, ist die Bedeutung — eine Kategorie hat ein
     * Archiv unter /news/kategorie/{slug}/, ein Schlagwort nicht.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE content_terms (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                taxonomy    TEXT    NOT NULL CHECK (taxonomy IN ('kategorie','schlagwort')),
                slug        TEXT    NOT NULL CHECK (slug <> ''),
                name        TEXT    NOT NULL CHECK (name <> ''),
                description TEXT    NOT NULL DEFAULT '',
                locale      TEXT    NOT NULL DEFAULT 'de-DE',
                created_at  TEXT    NOT NULL,
                UNIQUE (taxonomy, slug)
            )
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE content_entry_terms (
                entry_id INTEGER NOT NULL REFERENCES content_entries(id) ON DELETE CASCADE,
                term_id  INTEGER NOT NULL REFERENCES content_terms(id)   ON DELETE CASCADE,
                PRIMARY KEY (entry_id, term_id)
            ) WITHOUT ROWID
            SQL);

        // Die Gegenrichtung: das Archiv einer Kategorie.
        $pdo->exec('CREATE INDEX idx_content_entry_terms_begriff ON content_entry_terms(term_id, entry_id)');

        // Volltext ueber die Inhalte.
        //
        // Wie listing_search bewusst KEINE external-content-Tabelle: Der
        // Rumpftext entsteht aus den Bloecken und wird dabei aus Markdown zu
        // Reintext gemacht — das kann eine content-Tabelle nicht abbilden.
        // Gepflegt wird der Index vom ContentIndexer, vollstaendig neu
        // aufgebaut von bin/reindex.php.
        //
        // remove_diacritics 2 sorgt dafuer, dass "Koenigspython" und
        // "Königspython" denselben Token ergeben — dieselbe Einstellung wie
        // beim Anzeigenindex, sonst verhielten sich zwei Suchfelder derselben
        // Seite verschieden.
        $pdo->exec(<<<'SQL'
            CREATE VIRTUAL TABLE content_search USING fts5(
                title,
                excerpt,
                body_text,
                tokenize = 'unicode61 remove_diacritics 2'
            )
            SQL);

        // rowid des Index entspricht content_entries.id — geloeschte Eintraege
        // fallen automatisch raus.
        $pdo->exec(<<<'SQL'
            CREATE TRIGGER content_entries_search_delete AFTER DELETE ON content_entries
            BEGIN
                DELETE FROM content_search WHERE rowid = old.id;
            END
            SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TRIGGER IF EXISTS content_entries_search_delete');
        $pdo->exec('DROP TABLE IF EXISTS content_search');
        $pdo->exec('DROP TABLE IF EXISTS content_entry_terms');
        $pdo->exec('DROP TABLE IF EXISTS content_terms');
    }
};
