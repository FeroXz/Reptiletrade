<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Vererbungsrechnung (Phase 10).
     *
     * Zwei Aenderungen, die zusammengehoeren:
     *
     * 1. Der Merkmalskatalog kennt jetzt den geschlechtsgebundenen Erbgang.
     *    Er ist kein Sonderfall des rezessiven: Beim heterogametischen
     *    Geschlecht (ZW-System: dem Weibchen) genuegt eine einzige Anlage,
     *    damit das Merkmal sichtbar wird, und Toechter bekommen ihr Z immer vom
     *    Vater. Ohne eigenen Erbgang laesst sich das nicht rechnen. SQLite kann
     *    einen CHECK nicht nachtraeglich aendern, also wird die Tabelle
     *    umgebaut — der Migrator schaltet dafuer die Fremdschluessel ab und
     *    prueft sie danach.
     *
     * 2. Gespeicherte Simulationen. Das Ergebnis liegt als JSON und nicht in
     *    normalisierten Tabellen: Es ist ein Bericht zu einem Zeitpunkt, kein
     *    Datenbestand, ueber den abgefragt wird. Wuerde man die Verteilung in
     *    Zeilen aufloesen, waere jede spaetere Aenderung der Rechnung ein
     *    Migrationsproblem — und ein alter Bericht wuerde sich rueckwirkend
     *    aendern, obwohl er als Beleg fuer eine Verpaarung gespeichert wurde.
     *
     * Die Elterntiere haengen als Anzeigen dran, aber nur lose (ON DELETE SET
     * NULL): Eine geloeschte Anzeige darf einen Bericht nicht mitnehmen. Die
     * Angaben zu den Eltern stehen ohnehin im JSON.
     */
    public function up(PDO $pdo): void
    {
        // ------------------------------------------------- Merkmalskatalog
        $pdo->exec(<<<'SQL'
            CREATE TABLE morphs_neu (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                species_id      INTEGER NOT NULL REFERENCES species(id) ON DELETE CASCADE,
                name            TEXT    NOT NULL,
                aliases         TEXT    NOT NULL DEFAULT '[]' CHECK (json_valid(aliases)),
                inheritance     TEXT    NOT NULL
                                        CHECK (inheritance IN ('dominant','incomplete_dominant','recessive','polygenic','line_bred','paradox','sex_linked')),
                allele_group    TEXT,
                is_lethal_combo INTEGER NOT NULL DEFAULT 0 CHECK (is_lethal_combo IN (0,1)),
                description     TEXT,
                created_at      TEXT    NOT NULL,
                updated_at      TEXT    NOT NULL
            )
            SQL);

        $pdo->exec(<<<'SQL'
            INSERT INTO morphs_neu (
                id, species_id, name, aliases, inheritance, allele_group, is_lethal_combo, description,
                created_at, updated_at
            )
            SELECT
                id, species_id, name, aliases, inheritance, allele_group, is_lethal_combo, description,
                created_at, updated_at
            FROM morphs
            SQL);

        $pdo->exec('DROP TABLE morphs');
        $pdo->exec('ALTER TABLE morphs_neu RENAME TO morphs');

        $pdo->exec('CREATE UNIQUE INDEX uq_morphs_species_name ON morphs(species_id, name)');
        $pdo->exec('CREATE INDEX idx_morphs_species ON morphs(species_id)');
        $pdo->exec('CREATE INDEX idx_morphs_allele_group ON morphs(species_id, allele_group) WHERE allele_group IS NOT NULL');

        // --------------------------------------------- Gespeicherte Berichte
        $pdo->exec(<<<'SQL'
            CREATE TABLE genetics_simulations (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                species_id   INTEGER NOT NULL REFERENCES species(id) ON DELETE RESTRICT,
                listing_a_id INTEGER REFERENCES listings(id) ON DELETE SET NULL,
                listing_b_id INTEGER REFERENCES listings(id) ON DELETE SET NULL,
                titel        TEXT    NOT NULL DEFAULT '',
                result_json  TEXT    NOT NULL CHECK (json_valid(result_json)),
                created_at   TEXT    NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_genetics_simulations_user ON genetics_simulations(user_id, created_at DESC)');
        $pdo->exec('CREATE INDEX idx_genetics_simulations_species ON genetics_simulations(species_id)');

        $pdo->exec('ANALYZE');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS genetics_simulations');

        // Geschlechtsgebundene Merkmale gaebe es im alten CHECK nicht mehr.
        // Sie gehen auf "rezessiv" zurueck — der naechstliegende Erbgang, und
        // besser als ein Katalogeintrag, den die alte Fassung nicht laden kann.
        $pdo->exec("UPDATE morphs SET inheritance = 'recessive' WHERE inheritance = 'sex_linked'");

        $pdo->exec(<<<'SQL'
            CREATE TABLE morphs_alt (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                species_id      INTEGER NOT NULL REFERENCES species(id) ON DELETE CASCADE,
                name            TEXT    NOT NULL,
                aliases         TEXT    NOT NULL DEFAULT '[]' CHECK (json_valid(aliases)),
                inheritance     TEXT    NOT NULL
                                        CHECK (inheritance IN ('dominant','incomplete_dominant','recessive','polygenic','line_bred','paradox')),
                allele_group    TEXT,
                is_lethal_combo INTEGER NOT NULL DEFAULT 0 CHECK (is_lethal_combo IN (0,1)),
                description     TEXT,
                created_at      TEXT    NOT NULL,
                updated_at      TEXT    NOT NULL
            )
            SQL);

        $pdo->exec(<<<'SQL'
            INSERT INTO morphs_alt (
                id, species_id, name, aliases, inheritance, allele_group, is_lethal_combo, description,
                created_at, updated_at
            )
            SELECT
                id, species_id, name, aliases, inheritance, allele_group, is_lethal_combo, description,
                created_at, updated_at
            FROM morphs
            SQL);

        $pdo->exec('DROP TABLE morphs');
        $pdo->exec('ALTER TABLE morphs_alt RENAME TO morphs');

        $pdo->exec('CREATE UNIQUE INDEX uq_morphs_species_name ON morphs(species_id, name)');
        $pdo->exec('CREATE INDEX idx_morphs_species ON morphs(species_id)');
        $pdo->exec('CREATE INDEX idx_morphs_allele_group ON morphs(species_id, allele_group) WHERE allele_group IS NOT NULL');
    }
};
