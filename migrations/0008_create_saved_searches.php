<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Gespeicherte Suchen. filter_json haelt exakt die Facettenauswahl der
        // Suchseite; der Alert-Job vergleicht gegen last_seen_listing_id.
        $pdo->exec(<<<'SQL'
            CREATE TABLE saved_searches (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id              INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                name                 TEXT    NOT NULL,
                filter_json          TEXT    NOT NULL DEFAULT '{}' CHECK (json_valid(filter_json)),
                alert_frequency      TEXT    NOT NULL DEFAULT 'taeglich'
                                             CHECK (alert_frequency IN ('aus','sofort','taeglich','woechentlich')),
                last_alert_at        TEXT,
                last_seen_listing_id INTEGER,
                created_at           TEXT    NOT NULL,
                updated_at           TEXT    NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_saved_searches_user ON saved_searches(user_id)');
        $pdo->exec("CREATE INDEX idx_saved_searches_due ON saved_searches(alert_frequency, last_alert_at) WHERE alert_frequency <> 'aus'");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS saved_searches');
    }
};
