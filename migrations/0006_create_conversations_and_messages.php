<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Konversationen haengen immer an einer Anzeige. Je Anzeige und Interessent
        // gibt es genau einen Strang.
        $pdo->exec(<<<'SQL'
            CREATE TABLE conversations (
                id                      INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id              INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
                buyer_id                INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                seller_id               INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                status                  TEXT    NOT NULL DEFAULT 'offen'
                                                CHECK (status IN ('offen','geschlossen','gemeldet')),
                deal_confirmed_buyer_at  TEXT,                                             -- beidseitige Bestaetigung ist
                deal_confirmed_seller_at TEXT,                                             -- Voraussetzung fuer Bewertungen
                message_count           INTEGER NOT NULL DEFAULT 0,
                created_at              TEXT    NOT NULL,
                last_message_at         TEXT,
                CHECK (buyer_id <> seller_id)
            )
            SQL);

        $pdo->exec('CREATE UNIQUE INDEX uq_conversations_listing_buyer ON conversations(listing_id, buyer_id)');
        $pdo->exec('CREATE INDEX idx_conversations_buyer ON conversations(buyer_id, last_message_at DESC)');
        $pdo->exec('CREATE INDEX idx_conversations_seller ON conversations(seller_id, last_message_at DESC)');

        // sequence ist 1-basiert: Die ersten drei Nachrichten eines Strangs werden
        // beim Ausliefern maskiert (E-Mail-Adressen und Rufnummern, Anti-Scraping).
        $pdo->exec(<<<'SQL'
            CREATE TABLE messages (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
                sender_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                sequence        INTEGER NOT NULL CHECK (sequence > 0),
                body            TEXT    NOT NULL,
                flagged_reason  TEXT,                                                      -- Treffer des Betrugs-Keywordfilters
                read_at         TEXT,
                created_at      TEXT    NOT NULL
            )
            SQL);

        $pdo->exec('CREATE UNIQUE INDEX uq_messages_sequence ON messages(conversation_id, sequence)');
        $pdo->exec('CREATE INDEX idx_messages_unread ON messages(conversation_id, read_at)');
        $pdo->exec('CREATE INDEX idx_messages_sender ON messages(sender_id, created_at)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS messages');
        $pdo->exec('DROP TABLE IF EXISTS conversations');
    }
};
