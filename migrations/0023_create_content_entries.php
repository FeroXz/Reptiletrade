<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Seiten und Beitraege des Redaktionssystems — und ihre Bloecke.
     *
     * Eine Tabelle fuer beide Typen statt zweier fast gleicher: Die Felder
     * ueberlappen zu ueber 90 %, und zwei Tabellen hiessen jede Abfrage, jeden
     * Index, jede Revision und jeden Menueeintrag doppelt zu bauen. Was sich
     * unterscheidet, sichert die Datenbank ab — Eltern gibt es nur bei Seiten.
     *
     * Die Begruendungen im Einzelnen stehen in docs/CMS.md.
     */
    public function up(PDO $pdo): void
    {
        // path traegt den vollstaendig aufgeloesten Pfad. Die Alternative waere
        // ein rekursives CTE bei jedem Seitenaufruf; die Kollisionspruefung und
        // die Catch-all-Route brauchen aber einen Index, keine Rekursion.
        //
        // og_image_id fehlt hier bewusst: Bei eingeschaltetem
        // PRAGMA foreign_keys beantwortet SQLite jeden Schreibvorgang auf eine
        // Tabelle mit "no such table", solange die Elterntabelle eines
        // Fremdschluessels fehlt — auch bei NULL-Werten. Die Spalte kommt mit
        // der Medienmigration (0026) per ALTER TABLE ADD COLUMN dazu.
        $pdo->exec(<<<'SQL'
            CREATE TABLE content_entries (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                type             TEXT    NOT NULL CHECK (type IN ('seite','beitrag')),
                slug             TEXT    NOT NULL CHECK (slug <> ''),
                path             TEXT    NOT NULL UNIQUE CHECK (path LIKE '/%'),
                parent_id        INTEGER REFERENCES content_entries(id) ON DELETE RESTRICT,
                title            TEXT    NOT NULL CHECK (title <> ''),
                excerpt          TEXT    NOT NULL DEFAULT '',
                status           TEXT    NOT NULL DEFAULT 'entwurf'
                                         CHECK (status IN ('entwurf','geplant','veroeffentlicht','archiviert')),
                template         TEXT    NOT NULL DEFAULT 'standard'
                                         CHECK (template IN ('standard','breit','beitrag')),
                meta_title       TEXT,
                meta_description TEXT,
                noindex          INTEGER NOT NULL DEFAULT 0 CHECK (noindex IN (0, 1)),
                locale           TEXT    NOT NULL DEFAULT 'de-DE',
                published_at     TEXT,
                created_at       TEXT    NOT NULL,
                updated_at       TEXT    NOT NULL,
                author_id        INTEGER REFERENCES users(id) ON DELETE SET NULL,
                updated_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
                sort_order       INTEGER NOT NULL DEFAULT 0,

                -- Nur Seiten haengen unter einer anderen Seite. Ein Beitrag mit
                -- Elternteil waere ein Beitrag, dessen Pfad zwei Regeln folgt.
                CHECK (parent_id IS NULL OR type = 'seite'),

                -- Ein geplanter Eintrag ohne Termin ist ein Eintrag, der nie
                -- erscheint. Das faellt sonst erst auf, wenn jemand fragt,
                -- warum die Seite nicht online ist.
                CHECK (status <> 'geplant' OR published_at IS NOT NULL),

                -- Ein veroeffentlichter Eintrag ohne Zeitpunkt liesse sich
                -- weder sortieren noch in einen Feed schreiben.
                CHECK (status <> 'veroeffentlicht' OR published_at IS NOT NULL)
            )
            SQL);

        // Eindeutig je Elternteil — greift allerdings nicht bei parent_id IS
        // NULL, weil SQLite NULL-Werte in eindeutigen Indizes als verschieden
        // behandelt. Zwei Wurzelseiten mit demselben Slug faengt deshalb das
        // UNIQUE auf path ab: Der Pfad einer Wurzelseite ist genau /slug/.
        $pdo->exec('CREATE UNIQUE INDEX idx_content_entries_slug ON content_entries(type, parent_id, slug)');

        // Teilindex auf das, was die oeffentlichen Seiten abfragen. Entwuerfe
        // und Archiv stehen nicht darin — sie werden nie nach Datum sortiert
        // ausgeliefert.
        $pdo->exec(<<<'SQL'
            CREATE INDEX idx_content_entries_public ON content_entries(published_at DESC)
            WHERE status = 'veroeffentlicht'
            SQL);

        $pdo->exec('CREATE INDEX idx_content_entries_typ ON content_entries(type, status, published_at DESC)');

        // Die Seitenhierarchie in der Verwaltung und im Menue.
        $pdo->exec('CREATE INDEX idx_content_entries_eltern ON content_entries(parent_id, sort_order)');

        // Der Auftrag content.publish sucht genau hierueber.
        $pdo->exec(<<<'SQL'
            CREATE INDEX idx_content_entries_geplant ON content_entries(published_at)
            WHERE status = 'geplant'
            SQL);

        // Der Rumpf ist eine geordnete Blockliste, kein HTML-Feld. Ein HTML-Feld
        // zwingt entweder zu einem Sanitizer, dem man auf ewig hinterherpflegt,
        // oder zu 'unsafe-inline' in der CSP — beides teurer als ein festes
        // Blockvokabular.
        $pdo->exec(<<<'SQL'
            CREATE TABLE content_blocks (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                entry_id  INTEGER NOT NULL REFERENCES content_entries(id) ON DELETE CASCADE,
                position  INTEGER NOT NULL CHECK (position >= 0),
                type      TEXT    NOT NULL CHECK (type IN (
                              'text','bild','galerie','zitat','trenner','hinweis','cta',
                              'anzeigen-teaser','arten-teaser'
                          )),
                data_json TEXT    NOT NULL DEFAULT '{}' CHECK (json_valid(data_json))
            )
            SQL);

        // Bewusst kein UNIQUE auf (entry_id, position): Die Positionen werden
        // bei jedem Schreibvorgang lueckenlos von 0 an neu vergeben. Das ist
        // die staerkere Zusicherung — sie schliesst auch Luecken — und macht
        // das Verschieben eines Blocks zu einem Schreibvorgang statt zu einem
        // Tanz um Zwischenwerte.
        $pdo->exec('CREATE INDEX idx_content_blocks_reihenfolge ON content_blocks(entry_id, position)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS content_blocks');
        $pdo->exec('DROP TABLE IF EXISTS content_entries');
    }
};
