<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Volltextindex ueber Titel, Beschreibung, Morphnamen samt Aliases und Artnamen.
        //
        // Bewusst KEINE external-content-Tabelle: Die indexierten Morph- und Artnamen
        // stammen aus Joins ueber listing_morphs und species, die eine content-Tabelle
        // nicht abbilden kann. Gepflegt wird der Index vom SearchIndexer (Phase 3),
        // vollstaendig neu aufgebaut von bin/reindex.php.
        //
        // remove_diacritics 2 sorgt dafuer, dass "Koenigspython" und "Königspython"
        // denselben Token ergeben.
        $pdo->exec(<<<'SQL'
            CREATE VIRTUAL TABLE listing_search USING fts5(
                title,
                description,
                morphs,
                species,
                tokenize = 'unicode61 remove_diacritics 2'
            )
            SQL);

        // rowid des Index entspricht listings.id — geloeschte Anzeigen fallen automatisch raus.
        $pdo->exec(<<<'SQL'
            CREATE TRIGGER listings_search_delete AFTER DELETE ON listings
            BEGIN
                DELETE FROM listing_search WHERE rowid = old.id;
            END
            SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TRIGGER IF EXISTS listings_search_delete');
        $pdo->exec('DROP TABLE IF EXISTS listing_search');
    }
};
