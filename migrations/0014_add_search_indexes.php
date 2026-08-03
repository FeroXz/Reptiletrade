<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // bumped_at ist der Sortierschluessel der Trefferliste. Solange es NULL
        // sein kann, muesste sortiert werden ueber COALESCE(bumped_at, created_at) —
        // und darauf greift kein Index. Also: immer gesetzt.
        $pdo->exec('UPDATE listings SET bumped_at = created_at WHERE bumped_at IS NULL');

        $pdo->exec(<<<'SQL'
            CREATE TRIGGER listings_bumped_at_default AFTER INSERT ON listings
            WHEN new.bumped_at IS NULL
            BEGIN
                UPDATE listings SET bumped_at = new.created_at WHERE id = new.id;
            END
            SQL);

        // Trefferliste in Standardsortierung: Teilindex ueber die oeffentlich
        // sichtbaren Anzeigen. Die Reihenfolge im Index entspricht exakt dem
        // ORDER BY, damit SQLite gar nicht erst sortieren muss.
        //
        // id gehoert mit in den Index. Ohne die Spalte braucht SQLite fuer den
        // Gleichstand-Tiebreak einen temporaeren B-Baum und materialisiert die
        // gesamte Treffermenge vor dem LIMIT — gemessen 115 ms gegen 4,5 ms.
        $pdo->exec(<<<'SQL'
            CREATE INDEX idx_listings_rank ON listings(is_featured DESC, bumped_at DESC, id DESC)
                WHERE status IN ('aktiv','reserviert')
            SQL);

        // Facetten gruppieren ueber die gesamte sichtbare Menge. Je Dimension ein
        // schmaler Teilindex: SQLite liest ihn geordnet und braucht keinen
        // temporaeren B-Baum. Ein einzelner breiter Index waere fuer die hinteren
        // Spalten deutlich langsamer (gemessen: 4 ms gegen 127 ms).
        $pdo->exec("CREATE INDEX idx_listings_facet_sex ON listings(sex) WHERE status IN ('aktiv','reserviert')");
        $pdo->exec("CREATE INDEX idx_listings_facet_cb ON listings(cb_status) WHERE status IN ('aktiv','reserviert')");
        $pdo->exec("CREATE INDEX idx_listings_facet_handover ON listings(handover) WHERE status IN ('aktiv','reserviert')");

        // Ohne Statistiken waehlt SQLite fuer die Facetten den falschen Index.
        // Im Betrieb gehoert ANALYZE in die regelmaessigen Jobs (Phase 7).
        $pdo->exec('ANALYZE');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP INDEX IF EXISTS idx_listings_facet_handover');
        $pdo->exec('DROP INDEX IF EXISTS idx_listings_facet_cb');
        $pdo->exec('DROP INDEX IF EXISTS idx_listings_facet_sex');
        $pdo->exec('DROP INDEX IF EXISTS idx_listings_rank');
        $pdo->exec('DROP TRIGGER IF EXISTS listings_bumped_at_default');
    }
};
