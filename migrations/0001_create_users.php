<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Nutzerkonten. Rollen und Verifizierungsstufen sind hier bereits angelegt,
        // damit spaetere Phasen keine Tabellenumbauten brauchen (SQLite kann Spalten
        // mit CHECK-Constraints nicht nachtraeglich aendern).
        $pdo->exec(<<<'SQL'
            CREATE TABLE users (
                id                    INTEGER PRIMARY KEY AUTOINCREMENT,
                email                 TEXT    NOT NULL,                                    -- Anzeigeform, Gross-/Kleinschreibung erhalten
                email_canonical       TEXT    NOT NULL,                                    -- kleingeschrieben, fuer Login und Dublettenpruefung
                password_hash         TEXT    NOT NULL,                                    -- Argon2id
                display_name          TEXT    NOT NULL,
                role                  TEXT    NOT NULL DEFAULT 'buyer'
                                              CHECK (role IN ('buyer','seller','breeder','moderator','admin')),
                status                TEXT    NOT NULL DEFAULT 'aktiv'
                                              CHECK (status IN ('aktiv','gesperrt','geloescht')),
                email_verified_at     TEXT,                                                -- Verifizierungsstufe 1
                phone                 TEXT,
                phone_verified_at     TEXT,                                                -- Verifizierungsstufe 2
                identity_verified_at  TEXT,                                                -- Verifizierungsstufe 3, manuelle Pruefung
                is_commercial         INTEGER NOT NULL DEFAULT 0 CHECK (is_commercial IN (0,1)),
                erlaubnis_11_number   TEXT,                                                -- Erlaubnisnummer nach § 11 TierSchG
                imprint               TEXT,                                                -- Impressum gewerblicher Anbieter
                postal_code           TEXT,
                country               TEXT    CHECK (country IS NULL OR country IN ('DE','AT','CH')),
                lat                   REAL    CHECK (lat IS NULL OR (lat BETWEEN -90 AND 90)),
                lng                   REAL    CHECK (lng IS NULL OR (lng BETWEEN -180 AND 180)),
                totp_secret           TEXT,                                                -- optionale 2FA
                totp_confirmed_at     TEXT,
                failed_login_attempts INTEGER NOT NULL DEFAULT 0,
                locked_until          TEXT,
                last_login_at         TEXT,
                created_at            TEXT    NOT NULL,
                updated_at            TEXT    NOT NULL,
                anonymized_at         TEXT                                                 -- DSGVO: Anonymisierung statt Hard-Delete
            )
            SQL);

        $pdo->exec('CREATE UNIQUE INDEX uq_users_email_canonical ON users(email_canonical)');
        $pdo->exec('CREATE INDEX idx_users_role_status ON users(role, status)');
        $pdo->exec("CREATE INDEX idx_users_commercial ON users(is_commercial) WHERE is_commercial = 1");

        // Sessions liegen in der Datenbank, nicht im Dateisystem: erlaubt serveruebergreifendes
        // Invalidieren, Anzeige aktiver Geraete und Ablaufjobs.
        $pdo->exec(<<<'SQL'
            CREATE TABLE sessions (
                id                 TEXT    PRIMARY KEY,                                    -- 256 Bit Zufall, hex
                user_id            INTEGER REFERENCES users(id) ON DELETE CASCADE,
                ip_address         TEXT,
                user_agent         TEXT,
                payload            TEXT    NOT NULL DEFAULT '{}' CHECK (json_valid(payload)),
                two_factor_pending INTEGER NOT NULL DEFAULT 0 CHECK (two_factor_pending IN (0,1)),
                created_at         TEXT    NOT NULL,
                last_seen_at       TEXT    NOT NULL,
                expires_at         TEXT    NOT NULL
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_sessions_user ON sessions(user_id)');
        $pdo->exec('CREATE INDEX idx_sessions_expiry ON sessions(expires_at)');

        // Einmal-Token fuer Passwort-Reset und Verifizierung. Gespeichert wird nur der
        // Hash — der Klartext existiert ausschliesslich in der versendeten Mail bzw. SMS.
        $pdo->exec(<<<'SQL'
            CREATE TABLE user_tokens (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                type       TEXT    NOT NULL CHECK (type IN ('password_reset','email_verify','phone_verify')),
                token_hash TEXT    NOT NULL,
                created_at TEXT    NOT NULL,
                expires_at TEXT    NOT NULL,
                used_at    TEXT                                                            -- gesetzt = verbraucht (Single-Use)
            )
            SQL);

        $pdo->exec('CREATE UNIQUE INDEX uq_user_tokens_hash ON user_tokens(token_hash)');
        $pdo->exec('CREATE INDEX idx_user_tokens_user_type ON user_tokens(user_id, type)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS user_tokens');
        $pdo->exec('DROP TABLE IF EXISTS sessions');
        $pdo->exec('DROP TABLE IF EXISTS users');
    }
};
