<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Menues und Weiterleitungen.
     *
     * Ein Menueeintrag zeigt entweder auf einen Inhalt, auf eine feste Route
     * der Anwendung oder auf eine fremde Adresse. Drei Spalten fuer drei Faelle
     * waeren zwei leere je Zeile; deshalb target_type und target_value. Was
     * gilt, entscheidet der Typ — und ein Fremdschluessel auf content_entries
     * entfaellt dabei bewusst: Er waere nur in einem der drei Faelle sinnvoll,
     * und SQLite kennt keine bedingten Fremdschluessel. Statt dessen prueft
     * bin/doctor.php, ob jeder entry-Eintrag noch ein Ziel hat.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE menus (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                slug       TEXT    NOT NULL UNIQUE CHECK (slug <> ''),
                name       TEXT    NOT NULL CHECK (name <> ''),
                created_at TEXT    NOT NULL
            )
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE menu_items (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                menu_id      INTEGER NOT NULL REFERENCES menus(id) ON DELETE CASCADE,
                parent_id    INTEGER REFERENCES menu_items(id) ON DELETE CASCADE,
                label        TEXT    NOT NULL CHECK (label <> ''),
                target_type  TEXT    NOT NULL CHECK (target_type IN ('entry','route','url')),
                target_value TEXT    NOT NULL CHECK (target_value <> ''),
                sort_order   INTEGER NOT NULL DEFAULT 0,
                -- Wem wird der Eintrag gezeigt? "gast" blendet ihn fuer
                -- Angemeldete aus (etwa "Registrieren"), "angemeldet"
                -- umgekehrt.
                visibility   TEXT    NOT NULL DEFAULT 'alle'
                                     CHECK (visibility IN ('alle','angemeldet','gast','admin'))
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_menu_items_reihenfolge ON menu_items(menu_id, parent_id, sort_order)');

        $pdo->exec(<<<'SQL'
            CREATE TABLE content_redirects (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                from_path    TEXT    NOT NULL UNIQUE CHECK (from_path LIKE '/%'),
                to_path      TEXT    NOT NULL CHECK (to_path <> ''),
                code         INTEGER NOT NULL DEFAULT 301 CHECK (code IN (301, 302)),
                -- Zaehler und Zeitpunkt beantworten die einzige Frage, die man
                -- an eine alte Weiterleitung stellt: Braucht sie noch jemand?
                hits         INTEGER NOT NULL DEFAULT 0 CHECK (hits >= 0),
                last_used_at TEXT,
                created_at   TEXT    NOT NULL,
                created_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
                -- Bei einer Slug-Aenderung selbst angelegt. Automatische und
                -- von Hand angelegte unterscheiden sich in der Aufraeumfrage:
                -- Eine automatische darf verschwinden, wenn sie jahrelang
                -- niemand benutzt; eine von Hand angelegte steht dort mit Grund.
                is_auto      INTEGER NOT NULL DEFAULT 0 CHECK (is_auto IN (0, 1))
            )
            SQL);

        // Der Ausgangspfad hat bereits UNIQUE; dieser Index dient dem
        // Schleifenschutz, der in die Gegenrichtung fragt.
        $pdo->exec('CREATE INDEX idx_content_redirects_ziel ON content_redirects(to_path)');

        // Die beiden Menues, die die Oberflaeche kennt. Sie werden hier
        // angelegt und nicht von der Verwaltung erwartet: Ein Menue, das erst
        // jemand anlegen muss, damit die Kopfzeile funktioniert, ist eine
        // Installation, die halb fertig ausgeliefert wird.
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $statement = $pdo->prepare('INSERT INTO menus (slug, name, created_at) VALUES (:slug, :name, :now)');
        $statement->execute(['slug' => 'hauptmenu', 'name' => 'Hauptmenü', 'now' => $now]);
        $statement->execute(['slug' => 'fussbereich', 'name' => 'Fußbereich', 'now' => $now]);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS content_redirects');
        $pdo->exec('DROP TABLE IF EXISTS menu_items');
        $pdo->exec('DROP TABLE IF EXISTS menus');
    }
};
