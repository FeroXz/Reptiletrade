<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Artenstamm. Die Rechtsfelder (cites_appendix bis gefahrtier) sind die
        // alleinige Eingangsgroesse der Rechts-Engine aus Phase 2.
        $pdo->exec(<<<'SQL'
            CREATE TABLE species (
                id                       INTEGER PRIMARY KEY AUTOINCREMENT,
                scientific_name          TEXT    NOT NULL,                                 -- z. B. "Pogona vitticeps"
                common_name_de           TEXT    NOT NULL,                                 -- z. B. "Bartagame"
                family                   TEXT,                                             -- Familie, z. B. "Agamidae"
                order_taxon              TEXT,                                             -- Ordnung, z. B. "Squamata"
                cites_appendix           TEXT    CHECK (cites_appendix IS NULL OR cites_appendix IN ('I','II','III')),
                eu_annex                 TEXT    CHECK (eu_annex IS NULL OR eu_annex IN ('A','B','C','D')),
                bnatschg_status          TEXT    NOT NULL DEFAULT 'nicht_geschuetzt'
                                                 CHECK (bnatschg_status IN ('nicht_geschuetzt','besonders','streng')),
                meldepflicht             INTEGER NOT NULL DEFAULT 0 CHECK (meldepflicht IN (0,1)),   -- § 7 BArtSchV
                doku_pflicht             INTEGER NOT NULL DEFAULT 0 CHECK (doku_pflicht IN (0,1)),   -- Herkunftsnachweis noetig
                gefahrtier               INTEGER NOT NULL DEFAULT 0 CHECK (gefahrtier IN (0,1)),     -- Gefahrtierverordnungen der Laender
                care_level               TEXT    CHECK (care_level IS NULL OR care_level IN ('einsteiger','fortgeschritten','experte')),
                adult_size_cm            INTEGER CHECK (adult_size_cm IS NULL OR adult_size_cm > 0),
                lifespan_years           INTEGER CHECK (lifespan_years IS NULL OR lifespan_years > 0),
                min_abgabe_alter_wochen  INTEGER CHECK (min_abgabe_alter_wochen IS NULL OR min_abgabe_alter_wochen >= 0),
                min_abgabe_gewicht_g     INTEGER CHECK (min_abgabe_gewicht_g IS NULL OR min_abgabe_gewicht_g >= 0),
                slug                     TEXT    NOT NULL,                                 -- SEO-Pfad, z. B. "pogona-vitticeps"
                created_at               TEXT    NOT NULL,
                updated_at               TEXT    NOT NULL
            )
            SQL);

        $pdo->exec('CREATE UNIQUE INDEX uq_species_scientific_name ON species(scientific_name)');
        $pdo->exec('CREATE UNIQUE INDEX uq_species_slug ON species(slug)');
        $pdo->exec('CREATE INDEX idx_species_common_name ON species(common_name_de)');
        $pdo->exec('CREATE INDEX idx_species_legal ON species(eu_annex, bnatschg_status, meldepflicht)');
        $pdo->exec('CREATE INDEX idx_species_gefahrtier ON species(gefahrtier) WHERE gefahrtier = 1');

        // Merkmalskatalog je Art.
        $pdo->exec(<<<'SQL'
            CREATE TABLE morphs (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                species_id      INTEGER NOT NULL REFERENCES species(id) ON DELETE CASCADE,
                name            TEXT    NOT NULL,                                          -- kanonischer Name, z. B. "Hypomelanistic"
                aliases         TEXT    NOT NULL DEFAULT '[]' CHECK (json_valid(aliases)), -- handelsuebliche Zweitnamen, JSON-Array
                inheritance     TEXT    NOT NULL
                                        CHECK (inheritance IN ('dominant','incomplete_dominant','recessive','polygenic','line_bred','paradox')),
                allele_group    TEXT,                                                      -- gemeinsamer Genort (Super-Formen, Allelpaare)
                is_lethal_combo INTEGER NOT NULL DEFAULT 0 CHECK (is_lethal_combo IN (0,1)),
                description     TEXT,
                created_at      TEXT    NOT NULL,
                updated_at      TEXT    NOT NULL
            )
            SQL);

        $pdo->exec('CREATE UNIQUE INDEX uq_morphs_species_name ON morphs(species_id, name)');
        $pdo->exec('CREATE INDEX idx_morphs_species ON morphs(species_id)');
        $pdo->exec('CREATE INDEX idx_morphs_allele_group ON morphs(species_id, allele_group) WHERE allele_group IS NOT NULL');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS morphs');
        $pdo->exec('DROP TABLE IF EXISTS species');
    }
};
