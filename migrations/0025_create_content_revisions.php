<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Fassungen eines Eintrags und signierte Vorschaulinks.
     *
     * Eine Revision traegt Kopf UND Bloecke in einem JSON-Abbild. Zwei getrennte
     * Tabellen — eine fuer den Kopf, eine fuer die Bloecke — waeren
     * normalisierter, aber eine Revision ist kein Arbeitsobjekt: Sie wird
     * geschrieben, gelesen und im Ganzen zurueckgespielt, nie einzeln
     * abgefragt. Ein Abbild ist dafuer das ehrlichere Modell und macht das
     * Zuruecksetzen zu einem Schreibvorgang statt zu einem Abgleich.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE content_revisions (
                entry_id      INTEGER NOT NULL REFERENCES content_entries(id) ON DELETE CASCADE,
                revision_no   INTEGER NOT NULL CHECK (revision_no > 0),
                snapshot_json TEXT    NOT NULL CHECK (json_valid(snapshot_json)),
                author_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
                comment       TEXT    NOT NULL DEFAULT '',
                created_at    TEXT    NOT NULL,
                PRIMARY KEY (entry_id, revision_no)
            ) WITHOUT ROWID
            SQL);

        // Das Aufraeumen sucht je Eintrag die aeltesten Nummern.
        $pdo->exec('CREATE INDEX idx_content_revisions_alter ON content_revisions(entry_id, revision_no DESC)');

        // Vorschaulinks auf Entwuerfe.
        //
        // Gespeichert wird nur der Hash: Der Link wandert per E-Mail oder Chat
        // zu jemandem, der sich nicht anmelden kann, und wer die Datenbank
        // liest, soll ihn trotzdem nicht benutzen koennen — dieselbe Regel wie
        // bei den Einmal-Token in user_tokens.
        $pdo->exec(<<<'SQL'
            CREATE TABLE content_preview_tokens (
                token_hash TEXT    PRIMARY KEY,
                entry_id   INTEGER NOT NULL REFERENCES content_entries(id) ON DELETE CASCADE,
                expires_at TEXT    NOT NULL,
                created_at TEXT    NOT NULL,
                created_by INTEGER REFERENCES users(id) ON DELETE SET NULL
            ) WITHOUT ROWID
            SQL);

        $pdo->exec('CREATE INDEX idx_content_preview_ablauf ON content_preview_tokens(expires_at)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS content_preview_tokens');
        $pdo->exec('DROP TABLE IF EXISTS content_revisions');
    }
};
