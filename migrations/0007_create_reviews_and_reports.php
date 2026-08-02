<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Bewertungen sind nur nach beidseitig bestaetigtem Deal moeglich;
        // deal_confirmed_at haelt den Zeitpunkt dieser Bestaetigung fest.
        $pdo->exec(<<<'SQL'
            CREATE TABLE reviews (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id        INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
                from_user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                to_user_id        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                rating            INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
                comment           TEXT,
                deal_confirmed_at TEXT    NOT NULL,
                created_at        TEXT    NOT NULL,
                CHECK (from_user_id <> to_user_id)
            )
            SQL);

        $pdo->exec('CREATE UNIQUE INDEX uq_reviews_once_per_listing ON reviews(listing_id, from_user_id)');
        $pdo->exec('CREATE INDEX idx_reviews_to_user ON reviews(to_user_id, created_at DESC)');

        // Meldungen aus dem Meldebutton an Anzeigen, Profilen und Nachrichten.
        $pdo->exec(<<<'SQL'
            CREATE TABLE reports (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                reporter_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
                target_type     TEXT    NOT NULL CHECK (target_type IN ('listing','user','message')),
                target_id       INTEGER NOT NULL,
                reason          TEXT    NOT NULL
                                        CHECK (reason IN ('betrug','tierschutz','falsche_art','spam','sonstiges')),
                description     TEXT,
                status          TEXT    NOT NULL DEFAULT 'offen'
                                        CHECK (status IN ('offen','in_pruefung','erledigt','abgelehnt')),
                handled_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
                handled_at      TEXT,
                resolution_note TEXT,
                created_at      TEXT    NOT NULL
            )
            SQL);

        // Moderations-Queue: offene Meldungen, aelteste zuerst.
        $pdo->exec("CREATE INDEX idx_reports_queue ON reports(created_at) WHERE status IN ('offen','in_pruefung')");
        $pdo->exec('CREATE INDEX idx_reports_target ON reports(target_type, target_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS reports');
        $pdo->exec('DROP TABLE IF EXISTS reviews');
    }
};
