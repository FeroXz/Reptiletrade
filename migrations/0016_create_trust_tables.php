<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Zuechterprofil. Eigene Tabelle statt weiterer Spalten in users: Nur ein
        // Bruchteil der Konten hat ein Profil, und die oeffentliche Profilseite
        // liest ausschliesslich hier — die Kontodaten bleiben aussen vor.
        $pdo->exec(<<<'SQL'
            CREATE TABLE breeder_profiles (
                user_id            INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                slug               TEXT    NOT NULL,                                       -- /zuechter/{slug}/
                headline           TEXT,                                                   -- eine Zeile ueber der Beschreibung
                description        TEXT,
                focus_species_json TEXT    NOT NULL DEFAULT '[]'
                                           CHECK (json_valid(focus_species_json)),         -- Arten-Schwerpunkt, species.id
                breeding_since     INTEGER CHECK (breeding_since IS NULL
                                           OR (breeding_since BETWEEN 1950 AND 2200)),     -- Jahr, daraus die Zuchtjahre
                website            TEXT,
                is_public          INTEGER NOT NULL DEFAULT 1 CHECK (is_public IN (0,1)),
                created_at         TEXT    NOT NULL,
                updated_at         TEXT    NOT NULL
            )
            SQL);

        $pdo->exec('CREATE UNIQUE INDEX uq_breeder_profiles_slug ON breeder_profiles(slug)');

        // Nachweise zur Identitaets- und Gewerbepruefung. Getrennt von legal_docs,
        // weil die am Listing haengen und diese am Konto — und weil sie nach der
        // Pruefung anderen Aufbewahrungsfristen unterliegen (Phase 7).
        $pdo->exec(<<<'SQL'
            CREATE TABLE user_documents (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id           INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                doc_type          TEXT    NOT NULL CHECK (doc_type IN ('ausweis','gewerbenachweis')),
                private_path      TEXT    NOT NULL,                                        -- relativ zu storage/private/
                original_filename TEXT    NOT NULL,
                mime_type         TEXT    NOT NULL,
                byte_size         INTEGER NOT NULL,
                status            TEXT    NOT NULL DEFAULT 'offen'
                                          CHECK (status IN ('offen','geprueft','abgelehnt')),
                reviewed_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
                reviewed_at       TEXT,
                review_note       TEXT,
                created_at        TEXT    NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_user_documents_user ON user_documents(user_id, doc_type)');
        $pdo->exec("CREATE INDEX idx_user_documents_queue ON user_documents(created_at) WHERE status = 'offen'");

        // Rate-Limits als Ereignisliste statt als Zaehler: Ein Zaehler mit fester
        // Fensterlaenge laesst an der Fenstergrenze die doppelte Menge durch. Hier
        // wird ueber ein gleitendes Fenster gezaehlt, was diese Luecke schliesst.
        $pdo->exec(<<<'SQL'
            CREATE TABLE rate_limit_hits (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                bucket      TEXT NOT NULL,                                                 -- z. B. "nachricht:ip:203.0.113.7"
                occurred_at TEXT NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_rate_limit_hits_bucket ON rate_limit_hits(bucket, occurred_at)');

        // Der Keyword-Filter markiert Nachrichten; die Moderation braucht sie
        // schnell, ohne die ganze Tabelle zu lesen.
        $pdo->exec('CREATE INDEX idx_messages_flagged ON messages(created_at) WHERE flagged_reason IS NOT NULL');

        // Die Profilseite zeigt den Bewertungsschnitt — gezaehlt wird ueber to_user_id.
        $pdo->exec('CREATE INDEX idx_reviews_rating ON reviews(to_user_id, rating)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP INDEX IF EXISTS idx_reviews_rating');
        $pdo->exec('DROP INDEX IF EXISTS idx_messages_flagged');
        $pdo->exec('DROP TABLE IF EXISTS rate_limit_hits');
        $pdo->exec('DROP TABLE IF EXISTS user_documents');
        $pdo->exec('DROP TABLE IF EXISTS breeder_profiles');
    }
};
