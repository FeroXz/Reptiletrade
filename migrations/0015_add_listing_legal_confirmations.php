<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Die Bestaetigungen aus Schritt 5 des Assistenten (Meldung erfolgt,
        // Kennzeichnungsart, Chipnummer). Welche Felder verlangt werden, gibt
        // LegalGuard::requiredFields() je Art vor — deshalb ein JSON-Feld und
        // keine feste Spaltenliste.
        $pdo->exec(<<<'SQL'
            ALTER TABLE listings ADD COLUMN legal_confirmations TEXT NOT NULL DEFAULT '{}'
            SQL);

        // SQLite kann per ALTER TABLE keinen CHECK ergaenzen; ein Trigger tut es auch.
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
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TRIGGER IF EXISTS listings_legal_confirmations_json_insert');
        $pdo->exec('DROP TRIGGER IF EXISTS listings_legal_confirmations_json');
        $pdo->exec('ALTER TABLE listings DROP COLUMN legal_confirmations');
    }
};
