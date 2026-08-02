<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Rechtsnachweise zu einer Anzeige. Die Dateien liegen unter storage/private/
        // und werden ausschliesslich ueber einen authentifizierten Controller mit
        // readfile() ausgeliefert — nie ueber einen Webserver-Direktzugriff.
        $pdo->exec(<<<'SQL'
            CREATE TABLE legal_docs (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id        INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
                doc_type          TEXT    NOT NULL
                                          CHECK (doc_type IN ('herkunftsnachweis','eu_bescheinigung','meldung','elterntier','erlaubnis_11_tierschg')),
                reference_number  TEXT,                                                    -- amtliche Referenz, z. B. Bescheinigungsnummer
                issuing_authority TEXT,                                                    -- ausstellende Behoerde
                issue_date        TEXT,
                verified_at       TEXT,                                                    -- Freigabe durch Moderation
                verified_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
                private_path      TEXT,                                                    -- relativ zu storage/private
                original_filename TEXT,
                mime_type         TEXT,
                byte_size         INTEGER,
                created_at        TEXT    NOT NULL,
                updated_at        TEXT    NOT NULL,
                -- Absicherung gegen versehentliche Ablage im Webroot.
                CHECK (private_path IS NULL OR (private_path NOT LIKE 'public/%' AND private_path NOT LIKE '/%' AND private_path NOT LIKE '%..%'))
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_legal_docs_listing ON legal_docs(listing_id, doc_type)');
        // Pruef-Queue der Moderation.
        $pdo->exec('CREATE INDEX idx_legal_docs_pending ON legal_docs(created_at) WHERE verified_at IS NULL');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS legal_docs');
    }
};
