<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE listings (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                type            TEXT    NOT NULL
                                        CHECK (type IN ('verkauf','tausch','abgabe','gesuch','nachzucht_vorbestellung')),
                species_id      INTEGER NOT NULL REFERENCES species(id) ON DELETE RESTRICT,
                title           TEXT    NOT NULL,
                description     TEXT    NOT NULL DEFAULT '',
                price_cents     INTEGER CHECK (price_cents IS NULL OR price_cents >= 0),   -- ganzzahlig, nie Float
                currency        TEXT    NOT NULL DEFAULT 'EUR' CHECK (currency IN ('EUR','CHF')),
                negotiable      INTEGER NOT NULL DEFAULT 0 CHECK (negotiable IN (0,1)),    -- Preis verhandelbar
                trade_wanted    TEXT,                                                      -- Tauschwunsch im Klartext
                sex             TEXT    NOT NULL DEFAULT 'unbekannt' CHECK (sex IN ('m','w','unbekannt')),
                hatch_date      TEXT,                                                      -- Schlupfdatum, ISO-8601
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
                expires_at      TEXT,                                                      -- Laufzeitende, siehe Ablaufjobs
                bumped_at       TEXT,                                                      -- Sortierschluessel "neueste"
                view_count      INTEGER NOT NULL DEFAULT 0,
                is_featured     INTEGER NOT NULL DEFAULT 0 CHECK (is_featured IN (0,1))    -- Boost, Phase 6
            )
            SQL);

        // Facettensuche: Vorfilter immer ueber status, danach Art bzw. Sortierschluessel.
        $pdo->exec('CREATE INDEX idx_listings_status_bumped ON listings(status, bumped_at DESC)');
        $pdo->exec('CREATE INDEX idx_listings_species_status ON listings(species_id, status, bumped_at DESC)');
        $pdo->exec('CREATE INDEX idx_listings_type_status ON listings(type, status, bumped_at DESC)');
        $pdo->exec('CREATE INDEX idx_listings_price ON listings(status, price_cents)');
        $pdo->exec('CREATE INDEX idx_listings_user ON listings(user_id, status, updated_at DESC)');
        $pdo->exec('CREATE INDEX idx_listings_country_postal ON listings(country, postal_code, status)');
        $pdo->exec('CREATE INDEX idx_listings_expiry ON listings(status, expires_at) WHERE expires_at IS NOT NULL');
        // Umkreissuche: Teilindex nur ueber oeffentlich sichtbare Anzeigen mit Koordinaten.
        $pdo->exec(<<<'SQL'
            CREATE INDEX idx_listings_geo ON listings(lat, lng)
                WHERE status IN ('aktiv','reserviert') AND lat IS NOT NULL AND lng IS NOT NULL
            SQL);

        // Merkmale des konkreten Tieres.
        $pdo->exec(<<<'SQL'
            CREATE TABLE listing_morphs (
                listing_id INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
                morph_id   INTEGER NOT NULL REFERENCES morphs(id) ON DELETE RESTRICT,
                zygosity   TEXT    NOT NULL DEFAULT 'visual'
                                   CHECK (zygosity IN ('visual','het','poss_het_66','poss_het_50')),
                PRIMARY KEY (listing_id, morph_id)
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_listing_morphs_morph ON listing_morphs(morph_id, zygosity)');

        // Medien: maximal 12 Bilder und eine Video-URL je Anzeige (Trigger unten).
        $pdo->exec(<<<'SQL'
            CREATE TABLE listing_media (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
                media_type TEXT    NOT NULL DEFAULT 'bild' CHECK (media_type IN ('bild','video')),
                path       TEXT    NOT NULL,                                               -- Bild: Pfad unter public/uploads, Video: URL
                sort_order INTEGER NOT NULL DEFAULT 0,
                is_primary INTEGER NOT NULL DEFAULT 0 CHECK (is_primary IN (0,1)),
                width      INTEGER,
                height     INTEGER,
                byte_size  INTEGER,
                created_at TEXT    NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_listing_media_listing ON listing_media(listing_id, sort_order)');
        $pdo->exec('CREATE UNIQUE INDEX uq_listing_media_primary ON listing_media(listing_id) WHERE is_primary = 1');

        // Mengengrenzen auch in der Datenbank, nicht nur in der Anwendung.
        $pdo->exec(<<<'SQL'
            CREATE TRIGGER listing_media_max_images BEFORE INSERT ON listing_media
            WHEN new.media_type = 'bild'
                AND (SELECT COUNT(*) FROM listing_media WHERE listing_id = new.listing_id AND media_type = 'bild') >= 12
            BEGIN
                SELECT RAISE(ABORT, 'Maximal 12 Bilder je Anzeige');
            END
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TRIGGER listing_media_max_videos BEFORE INSERT ON listing_media
            WHEN new.media_type = 'video'
                AND (SELECT COUNT(*) FROM listing_media WHERE listing_id = new.listing_id AND media_type = 'video') >= 1
            BEGIN
                SELECT RAISE(ABORT, 'Maximal eine Video-URL je Anzeige');
            END
            SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TRIGGER IF EXISTS listing_media_max_videos');
        $pdo->exec('DROP TRIGGER IF EXISTS listing_media_max_images');
        $pdo->exec('DROP TABLE IF EXISTS listing_media');
        $pdo->exec('DROP TABLE IF EXISTS listing_morphs');
        $pdo->exec('DROP TABLE IF EXISTS listings');
    }
};
