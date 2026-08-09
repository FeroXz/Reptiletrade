<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Die Merkliste.
     *
     * Der zusammengesetzte Primaerschluessel ist zugleich der eindeutige Index
     * ueber beide Spalten: Zweimal dieselbe Anzeige zu merken ist keine zweite
     * Merkung, sondern derselbe Wunsch. Das gehoert in die Datenbank und nicht
     * in eine Pruefung davor — sonst entscheidet ein Wettlauf zwischen zwei
     * Klicks, ob es eine oder zwei Zeilen gibt.
     *
     * Beide Fremdschluessel loeschen mit: Verschwindet die Anzeige, hat der
     * Eintrag kein Ziel mehr; verschwindet das Konto, gehoert er niemandem.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE listing_favorites (
                user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                listing_id INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
                created_at TEXT    NOT NULL,
                PRIMARY KEY (user_id, listing_id)
            ) WITHOUT ROWID
            SQL);

        // Der Anbieter fragt nach der Anzeige, nicht nach dem Konto — und der
        // Primaerschluessel beginnt mit der falschen Spalte dafuer.
        $pdo->exec('CREATE INDEX idx_listing_favorites_listing ON listing_favorites(listing_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS listing_favorites');
    }
};
