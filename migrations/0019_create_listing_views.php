<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Aufrufe je Anzeige und Tag.
     *
     * Eine Zeile je Tag statt je Aufruf: Ein Marktplatz mit ein paar tausend
     * Anzeigen erzeugt sonst Millionen Zeilen, die niemand einzeln liest — die
     * Statistik fragt immer nach Summen ueber Zeitraeume. Der Tagesschluessel
     * ist UTC, wie alle Zeitstempel hier.
     *
     * Anfragen bekommen keine eigene Tabelle: Die stehen bereits in
     * conversations mit created_at, und eine zweite Zaehlung waere eine zweite
     * Wahrheit ueber dieselbe Sache.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE listing_views (
                listing_id INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
                day        TEXT    NOT NULL,                    -- YYYY-MM-DD, UTC
                views      INTEGER NOT NULL DEFAULT 0 CHECK (views >= 0),
                PRIMARY KEY (listing_id, day)
            ) WITHOUT ROWID
            SQL);

        // Die Statistik eines Anbieters liest ueber alle seine Anzeigen hinweg
        // nach Tag — deshalb der Tag zuerst.
        $pdo->exec('CREATE INDEX idx_listing_views_day ON listing_views(day, listing_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS listing_views');
    }
};
