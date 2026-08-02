<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Postleitzahlen mit Zentroid. Lokal eingebettet, kein Laufzeit-API-Call.
        // Herkunft und Genauigkeit je Zeile in "source", Details in data/README.md.
        $pdo->exec(<<<'SQL'
            CREATE TABLE postal_codes (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                country     TEXT    NOT NULL CHECK (country IN ('DE','AT','CH')),
                postal_code TEXT    NOT NULL,
                place_name  TEXT    NOT NULL,
                admin1      TEXT,                                                          -- Bundesland bzw. Kanton
                lat         REAL    NOT NULL CHECK (lat BETWEEN -90 AND 90),
                lng         REAL    NOT NULL CHECK (lng BETWEEN -180 AND 180),
                source      TEXT    NOT NULL DEFAULT 'geonames'
                                    CHECK (source IN ('geonames','geonames_place','interpoliert'))
            )
            SQL);

        $pdo->exec('CREATE UNIQUE INDEX uq_postal_codes ON postal_codes(country, postal_code)');
        // Vorfilter der Umkreissuche laeuft ueber diesen Index (Bounding Box), erst danach Haversine.
        $pdo->exec('CREATE INDEX idx_postal_codes_bbox ON postal_codes(lat, lng)');
        $pdo->exec('CREATE INDEX idx_postal_codes_place ON postal_codes(place_name)');
        $pdo->exec('CREATE INDEX idx_postal_codes_admin1 ON postal_codes(country, admin1)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS postal_codes');
    }
};
