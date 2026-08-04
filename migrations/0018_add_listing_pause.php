<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Pausieren von Anzeigen — durch den Anbieter selbst oder durch die
     * Verwaltung.
     *
     * "pausiert" wird ein eigener Zustand in status und **kein** zweites Feld
     * neben ihm. Ein Kennzeichen wie is_paused waere eine zweite Wahrheit ueber
     * dieselbe Frage ("ist die Anzeige zu sehen?"), und jede Suchabfrage
     * muesste beides pruefen. Genau daran haengen hier die Teilindizes:
     * idx_listings_rank, die drei Facettenindizes und der Geo-Index sind alle
     * ueber "status IN ('aktiv','reserviert')" eingeschraenkt. Ein zusaetzliches
     * "AND is_paused = 0" haette sie unbrauchbar gemacht — in Phase 3 gemessen
     * war das der Unterschied zwischen 4,5 ms und 115 ms.
     *
     * Der Preis dafuer: SQLite kann einen CHECK nicht nachtraeglich aendern,
     * also wird die Tabelle umgebaut. Der Migrator schaltet dafuer die
     * Fremdschluessel ab und prueft sie danach — ohne das wuerde DROP TABLE
     * die abhaengigen Zeilen mitloeschen.
     */
    public function up(PDO $pdo): void
    {
        // ---------------------------------------------------------- Umbau
        $pdo->exec(<<<'SQL'
            CREATE TABLE listings_neu (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                type            TEXT    NOT NULL
                                        CHECK (type IN ('verkauf','tausch','abgabe','gesuch','nachzucht_vorbestellung')),
                species_id      INTEGER NOT NULL REFERENCES species(id) ON DELETE RESTRICT,
                title           TEXT    NOT NULL,
                description     TEXT    NOT NULL DEFAULT '',
                price_cents     INTEGER CHECK (price_cents IS NULL OR price_cents >= 0),
                currency        TEXT    NOT NULL DEFAULT 'EUR' CHECK (currency IN ('EUR','CHF')),
                negotiable      INTEGER NOT NULL DEFAULT 0 CHECK (negotiable IN (0,1)),
                trade_wanted    TEXT,
                sex             TEXT    NOT NULL DEFAULT 'unbekannt' CHECK (sex IN ('m','w','unbekannt')),
                hatch_date      TEXT,
                weight_g        INTEGER CHECK (weight_g IS NULL OR weight_g > 0),
                count_available INTEGER NOT NULL DEFAULT 1 CHECK (count_available >= 0),
                cb_status       TEXT    NOT NULL DEFAULT 'unbekannt' CHECK (cb_status IN ('nz','wf','unbekannt')),
                status          TEXT    NOT NULL DEFAULT 'entwurf'
                                        CHECK (status IN ('entwurf','pruefung','aktiv','reserviert','pausiert','verkauft','abgelaufen','gesperrt')),
                postal_code     TEXT,
                country         TEXT    CHECK (country IS NULL OR country IN ('DE','AT','CH')),
                lat             REAL    CHECK (lat IS NULL OR (lat BETWEEN -90 AND 90)),
                lng             REAL    CHECK (lng IS NULL OR (lng BETWEEN -180 AND 180)),
                handover        TEXT    NOT NULL DEFAULT 'abholung'
                                        CHECK (handover IN ('abholung','uebergabe_boerse','tiertransport')),
                created_at      TEXT    NOT NULL,
                updated_at      TEXT    NOT NULL,
                expires_at      TEXT,
                bumped_at       TEXT,
                view_count      INTEGER NOT NULL DEFAULT 0,
                is_featured     INTEGER NOT NULL DEFAULT 0 CHECK (is_featured IN (0,1)),
                legal_confirmations TEXT NOT NULL DEFAULT '{}',

                -- Wer hat pausiert? Der Unterschied entscheidet, wer wieder
                -- fortsetzen darf: Eine Pause der Verwaltung hebt der Anbieter
                -- nicht selbst auf, sonst waere die Massnahme wertlos.
                paused_by       TEXT    CHECK (paused_by IS NULL OR paused_by IN ('anbieter','verwaltung')),
                paused_at       TEXT,
                paused_reason   TEXT,
                -- Wohin es beim Fortsetzen zurueckgeht. Eine reservierte Anzeige
                -- soll nach der Pause nicht ploetzlich wieder frei sein.
                status_before_pause TEXT,

                -- Bearbeitungen nach der Veroeffentlichung.
                edited_at       TEXT,
                edit_count      INTEGER NOT NULL DEFAULT 0,

                CHECK ((paused_by IS NULL) = (paused_at IS NULL))
            )
            SQL);

        $pdo->exec(<<<'SQL'
            INSERT INTO listings_neu (
                id, user_id, type, species_id, title, description, price_cents, currency, negotiable,
                trade_wanted, sex, hatch_date, weight_g, count_available, cb_status, status, postal_code,
                country, lat, lng, handover, created_at, updated_at, expires_at, bumped_at, view_count,
                is_featured, legal_confirmations
            )
            SELECT
                id, user_id, type, species_id, title, description, price_cents, currency, negotiable,
                trade_wanted, sex, hatch_date, weight_g, count_available, cb_status, status, postal_code,
                country, lat, lng, handover, created_at, updated_at, expires_at, bumped_at, view_count,
                is_featured, legal_confirmations
            FROM listings
            SQL);

        $pdo->exec('DROP TABLE listings');
        $pdo->exec('ALTER TABLE listings_neu RENAME TO listings');

        $this->createIndexesAndTriggers($pdo, true);

        // -------------------------------------------- Aenderungsprotokoll
        //
        // Ein Protokoll je Bearbeitung, nicht je Anzeige: Eine Anzeige laesst
        // sich mehrfach aendern, und genau die Abfolge ist der Zweck. Ein
        // UNIQUE auf listing_id wuerde daraus einen einzigen Eintrag machen und
        // die Tabelle sinnlos.
        $pdo->exec(<<<'SQL'
            CREATE TABLE listing_edits (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id    INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
                edited_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
                changed_json  TEXT    NOT NULL DEFAULT '{}' CHECK (json_valid(changed_json)),
                edited_at     TEXT    NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_listing_edits_listing ON listing_edits(listing_id, edited_at DESC)');

        $pdo->exec('ANALYZE');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS listing_edits');

        // Pausierte Anzeigen gaebe es im alten CHECK nicht mehr. Sie werden auf
        // ihren Zustand vor der Pause zurueckgesetzt — und wo der fehlt, auf
        // "gesperrt": lieber unsichtbar als versehentlich wieder oeffentlich.
        $pdo->exec(<<<'SQL'
            UPDATE listings
               SET status = COALESCE(NULLIF(status_before_pause, 'pausiert'), 'gesperrt')
             WHERE status = 'pausiert'
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE listings_alt (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                type            TEXT    NOT NULL
                                        CHECK (type IN ('verkauf','tausch','abgabe','gesuch','nachzucht_vorbestellung')),
                species_id      INTEGER NOT NULL REFERENCES species(id) ON DELETE RESTRICT,
                title           TEXT    NOT NULL,
                description     TEXT    NOT NULL DEFAULT '',
                price_cents     INTEGER CHECK (price_cents IS NULL OR price_cents >= 0),
                currency        TEXT    NOT NULL DEFAULT 'EUR' CHECK (currency IN ('EUR','CHF')),
                negotiable      INTEGER NOT NULL DEFAULT 0 CHECK (negotiable IN (0,1)),
                trade_wanted    TEXT,
                sex             TEXT    NOT NULL DEFAULT 'unbekannt' CHECK (sex IN ('m','w','unbekannt')),
                hatch_date      TEXT,
                weight_g        INTEGER CHECK (weight_g IS NULL OR weight_g > 0),
                count_available INTEGER NOT NULL DEFAULT 1 CHECK (count_available >= 0),
                cb_status       TEXT    NOT NULL DEFAULT 'unbekannt' CHECK (cb_status IN ('nz','wf','unbekannt')),
                status          TEXT    NOT NULL DEFAULT 'entwurf'
                                        CHECK (status IN ('entwurf','pruefung','aktiv','reserviert','verkauft','abgelaufen','gesperrt')),
                postal_code     TEXT,
                country         TEXT    CHECK (country IS NULL OR country IN ('DE','AT','CH')),
                lat             REAL    CHECK (lat IS NULL OR (lat BETWEEN -90 AND 90)),
                lng             REAL    CHECK (lng IS NULL OR (lng BETWEEN -180 AND 180)),
                handover        TEXT    NOT NULL DEFAULT 'abholung'
                                        CHECK (handover IN ('abholung','uebergabe_boerse','tiertransport')),
                created_at      TEXT    NOT NULL,
                updated_at      TEXT    NOT NULL,
                expires_at      TEXT,
                bumped_at       TEXT,
                view_count      INTEGER NOT NULL DEFAULT 0,
                is_featured     INTEGER NOT NULL DEFAULT 0 CHECK (is_featured IN (0,1)),
                legal_confirmations TEXT NOT NULL DEFAULT '{}'
            )
            SQL);

        $pdo->exec(<<<'SQL'
            INSERT INTO listings_alt
            SELECT
                id, user_id, type, species_id, title, description, price_cents, currency, negotiable,
                trade_wanted, sex, hatch_date, weight_g, count_available, cb_status, status, postal_code,
                country, lat, lng, handover, created_at, updated_at, expires_at, bumped_at, view_count,
                is_featured, legal_confirmations
            FROM listings
            SQL);

        $pdo->exec('DROP TABLE listings');
        $pdo->exec('ALTER TABLE listings_alt RENAME TO listings');

        $this->createIndexesAndTriggers($pdo, false);
        $pdo->exec('ANALYZE');
    }

    /**
     * Indizes und Trigger der Tabelle listings — wortgleich zu 0004, 0012, 0013
     * und 0014. Ein Umbau muss alles wiederherstellen, was an der alten Tabelle
     * hing; vergessene Teilindizes faellt erst im Betrieb auf, und dann als
     * langsame Suche.
     */
    private function createIndexesAndTriggers(PDO $pdo, bool $withPause): void
    {
        $pdo->exec('CREATE INDEX idx_listings_status_bumped ON listings(status, bumped_at DESC)');
        $pdo->exec('CREATE INDEX idx_listings_species_status ON listings(species_id, status, bumped_at DESC)');
        $pdo->exec('CREATE INDEX idx_listings_type_status ON listings(type, status, bumped_at DESC)');
        $pdo->exec('CREATE INDEX idx_listings_price ON listings(status, price_cents)');
        $pdo->exec('CREATE INDEX idx_listings_user ON listings(user_id, status, updated_at DESC)');
        $pdo->exec('CREATE INDEX idx_listings_country_postal ON listings(country, postal_code, status)');
        $pdo->exec('CREATE INDEX idx_listings_expiry ON listings(status, expires_at) WHERE expires_at IS NOT NULL');
        $pdo->exec(<<<'SQL'
            CREATE INDEX idx_listings_geo ON listings(lat, lng)
                WHERE status IN ('aktiv','reserviert') AND lat IS NOT NULL AND lng IS NOT NULL
            SQL);

        // 0013
        $pdo->exec('CREATE INDEX idx_listings_status_hatch ON listings(status, hatch_date) WHERE hatch_date IS NOT NULL');

        // 0014
        $pdo->exec(<<<'SQL'
            CREATE INDEX idx_listings_rank ON listings(is_featured DESC, bumped_at DESC, id DESC)
                WHERE status IN ('aktiv','reserviert')
            SQL);
        $pdo->exec("CREATE INDEX idx_listings_facet_sex ON listings(sex) WHERE status IN ('aktiv','reserviert')");
        $pdo->exec("CREATE INDEX idx_listings_facet_cb ON listings(cb_status) WHERE status IN ('aktiv','reserviert')");
        $pdo->exec("CREATE INDEX idx_listings_facet_handover ON listings(handover) WHERE status IN ('aktiv','reserviert')");

        if ($withPause) {
            // Die Liste der Verwaltung: pausierte Anzeigen, neueste zuerst.
            $pdo->exec('CREATE INDEX idx_listings_paused ON listings(paused_at DESC) WHERE paused_at IS NOT NULL');
        }

        $pdo->exec(<<<'SQL'
            CREATE TRIGGER listings_bumped_at_default AFTER INSERT ON listings
            WHEN new.bumped_at IS NULL
            BEGIN
                UPDATE listings SET bumped_at = new.created_at WHERE id = new.id;
            END
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TRIGGER listings_legal_confirmations_json BEFORE UPDATE OF legal_confirmations ON listings
            WHEN json_valid(new.legal_confirmations) = 0
            BEGIN
                SELECT RAISE(ABORT, 'legal_confirmations muss gueltiges JSON sein');
            END
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TRIGGER listings_legal_confirmations_json_insert BEFORE INSERT ON listings
            WHEN json_valid(new.legal_confirmations) = 0
            BEGIN
                SELECT RAISE(ABORT, 'legal_confirmations muss gueltiges JSON sein');
            END
            SQL);

        // 0012: Der Volltextindex haengt an listings.id.
        $pdo->exec(<<<'SQL'
            CREATE TRIGGER listings_search_delete AFTER DELETE ON listings
            BEGIN
                DELETE FROM listing_search WHERE rowid = old.id;
            END
            SQL);
    }
};
