<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Rechtstexte redaktionell pflegbar, nicht im Code. last_reviewed_at treibt
        // die Admin-Warnung "Rechtstext seit ueber 12 Monaten nicht geprueft".
        // Die Aktualisierungspflicht liegt beim Betreiber, die Plattform leistet
        // keine Rechtsberatung.
        $pdo->exec(<<<'SQL'
            CREATE TABLE legal_texts (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                text_key          TEXT    NOT NULL,                                        -- z. B. "hinweis.anhang_b"
                title             TEXT    NOT NULL,
                body              TEXT    NOT NULL,
                source_reference  TEXT,                                                    -- Fundstelle, z. B. "§ 7 Abs. 2 BArtSchV"
                jurisdiction      TEXT    NOT NULL DEFAULT 'DE'
                                          CHECK (jurisdiction IN ('DE','AT','CH','EU')),
                last_reviewed_at  TEXT,
                last_reviewed_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
                created_at        TEXT    NOT NULL,
                updated_at        TEXT    NOT NULL
            )
            SQL);

        $pdo->exec('CREATE UNIQUE INDEX uq_legal_texts_key ON legal_texts(text_key, jurisdiction)');
        $pdo->exec('CREATE INDEX idx_legal_texts_review ON legal_texts(last_reviewed_at)');

        // Betriebsschalter, u. a. die global abschaltbare Gefahrtier-Durchsetzung.
        $pdo->exec(<<<'SQL'
            CREATE TABLE settings (
                setting_key TEXT PRIMARY KEY,
                value       TEXT NOT NULL,
                value_type  TEXT NOT NULL DEFAULT 'string'
                                 CHECK (value_type IN ('string','int','bool','json')),
                description TEXT,
                updated_at  TEXT NOT NULL
            )
            SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS settings');
        $pdo->exec('DROP TABLE IF EXISTS legal_texts');
    }
};
