<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Abos. Der Tarif selbst steht in config/monetarisierung.php — hier
        // steht nur, wer welchen hat und wie lange. Ein Preis in dieser Tabelle
        // waere doppelt gepflegt und irgendwann widersprüchlich.
        $pdo->exec(<<<'SQL'
            CREATE TABLE subscriptions (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id              INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                plan_key             TEXT    NOT NULL,                                       -- Schluessel aus der Konfiguration
                status               TEXT    NOT NULL DEFAULT 'aktiv'
                                             CHECK (status IN ('aktiv','gekuendigt','abgelaufen','zahlung_offen')),
                provider             TEXT    NOT NULL DEFAULT 'keiner',
                provider_reference   TEXT,                                                   -- Kennung beim Zahlungsanbieter
                current_period_start TEXT    NOT NULL,
                current_period_end   TEXT    NOT NULL,
                cancel_at_period_end INTEGER NOT NULL DEFAULT 0 CHECK (cancel_at_period_end IN (0,1)),
                created_at           TEXT    NOT NULL,
                updated_at           TEXT    NOT NULL
            )
            SQL);

        // Pro Konto hoechstens ein laufendes Abo — zwei gleichzeitige waeren
        // eine Abrechnung, die niemand erklaeren kann.
        $pdo->exec(<<<'SQL'
            CREATE UNIQUE INDEX uq_subscriptions_active ON subscriptions(user_id)
                WHERE status IN ('aktiv','zahlung_offen')
            SQL);

        $pdo->exec('CREATE INDEX idx_subscriptions_period ON subscriptions(current_period_end) '
            . "WHERE status = 'aktiv'");

        // Zahlungen. Append-only gedacht: Ein Beleg wird nicht geaendert,
        // sondern durch einen weiteren Beleg (Gutschrift) ausgeglichen.
        $pdo->exec(<<<'SQL'
            CREATE TABLE payments (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id            INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                purpose            TEXT    NOT NULL CHECK (purpose IN ('abo','boost')),
                reference_type     TEXT,                                                     -- 'subscription' oder 'listing'
                reference_id       INTEGER,
                amount_cents       INTEGER NOT NULL CHECK (amount_cents >= 0),
                currency           TEXT    NOT NULL DEFAULT 'EUR' CHECK (length(currency) = 3),
                tax_rate           REAL    NOT NULL DEFAULT 0,
                status             TEXT    NOT NULL DEFAULT 'offen'
                                           CHECK (status IN ('offen','bezahlt','fehlgeschlagen','erstattet')),
                provider           TEXT    NOT NULL DEFAULT 'keiner',
                provider_reference TEXT,
                paid_at            TEXT,
                created_at         TEXT    NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_payments_user ON payments(user_id, created_at DESC)');

        // Dieselbe Zahlung darf nicht zweimal ankommen: Ein Webhook wird
        // wiederholt zugestellt, wenn die erste Antwort verlorengeht.
        $pdo->exec(<<<'SQL'
            CREATE UNIQUE INDEX uq_payments_provider_reference ON payments(provider, provider_reference)
                WHERE provider_reference IS NOT NULL
            SQL);

        // Boosts. Die Top-Platzierung selbst liest die Suche aus
        // listings.is_featured — hier steht, warum und wie lange.
        $pdo->exec(<<<'SQL'
            CREATE TABLE listing_boosts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id INTEGER NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
                boost_key  TEXT    NOT NULL,                                                 -- Schluessel aus der Konfiguration
                payment_id INTEGER REFERENCES payments(id) ON DELETE SET NULL,
                starts_at  TEXT    NOT NULL,
                ends_at    TEXT    NOT NULL,
                created_at TEXT    NOT NULL,
                CHECK (ends_at > starts_at)
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_listing_boosts_listing ON listing_boosts(listing_id, ends_at DESC)');

        // Der Ablaufjob (Phase 7) sucht genau danach: laufende Boosts, deren
        // Ende erreicht ist.
        $pdo->exec('CREATE INDEX idx_listing_boosts_ends ON listing_boosts(ends_at)');

        // Nachzucht-Ankuendigungen — ein Merkmal des Zuechter-Abos. Sie haengen
        // an der Art, nicht an einer Anzeige: Angekuendigt wird, was es noch
        // nicht gibt.
        $pdo->exec(<<<'SQL'
            CREATE TABLE breeding_announcements (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                species_id  INTEGER NOT NULL REFERENCES species(id) ON DELETE CASCADE,
                title       TEXT    NOT NULL,
                description TEXT,
                expected_at TEXT,                                                            -- erwarteter Schlupf
                morph_note  TEXT,                                                            -- Verpaarung in Worten
                status      TEXT    NOT NULL DEFAULT 'entwurf'
                                    CHECK (status IN ('entwurf','veroeffentlicht','erledigt','zurueckgezogen')),
                created_at  TEXT    NOT NULL,
                updated_at  TEXT    NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_breeding_announcements_user ON breeding_announcements(user_id, created_at DESC)');
        $pdo->exec("CREATE INDEX idx_breeding_announcements_public ON breeding_announcements(species_id, expected_at) "
            . "WHERE status = 'veroeffentlicht'");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS breeding_announcements');
        $pdo->exec('DROP TABLE IF EXISTS listing_boosts');
        $pdo->exec('DROP TABLE IF EXISTS payments');
        $pdo->exec('DROP TABLE IF EXISTS subscriptions');
    }
};
