<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Kontosperren durch die Verwaltung und das Kontaktformular.
     *
     * Die Sperre bekommt eigene Spalten und nutzt nicht locked_until: Das ist
     * die Bremse nach zu vielen Fehlversuchen und wird bei jeder erfolgreichen
     * Anmeldung geleert. Eine Massnahme der Verwaltung darf nicht davon
     * abhaengen, ob jemand sein Passwort richtig eintippt.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users ADD COLUMN banned_at TEXT');
        // NULL bei einer unbefristeten Sperre — der Unterschied zwischen
        // "bis Freitag" und "auf Dauer" ist genau diese Spalte.
        $pdo->exec('ALTER TABLE users ADD COLUMN banned_until TEXT');
        $pdo->exec('ALTER TABLE users ADD COLUMN banned_reason TEXT');
        $pdo->exec('ALTER TABLE users ADD COLUMN banned_by INTEGER REFERENCES users(id) ON DELETE SET NULL');

        // Der Job, der abgelaufene Sperren aufhebt, fragt genau danach.
        $pdo->exec('CREATE INDEX idx_users_banned_until ON users(banned_until) WHERE banned_until IS NOT NULL');

        // -------------------------------------------------------- Kontakt
        //
        // Anfragen landen in der Datenbank, nicht nur in einer Mail: Eine Mail
        // geht im Postfach unter, und niemand sieht, was noch offen ist. Die
        // Adresse wird mitgespeichert, weil auch Nichtangemeldete schreiben
        // koennen muessen — sonst bliebe ausgerechnet der ausgesperrt, dessen
        // Konto gesperrt wurde.
        $pdo->exec(<<<'SQL'
            CREATE TABLE contact_messages (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id      INTEGER REFERENCES users(id) ON DELETE SET NULL,
                name         TEXT    NOT NULL,
                email        TEXT    NOT NULL,
                topic        TEXT    NOT NULL
                                     CHECK (topic IN ('frage','anzeige','konto','recht','missbrauch','sonstiges')),
                subject      TEXT    NOT NULL,
                body         TEXT    NOT NULL,
                status       TEXT    NOT NULL DEFAULT 'offen'
                                     CHECK (status IN ('offen','erledigt')),
                ip_address   TEXT,
                handled_by   INTEGER REFERENCES users(id) ON DELETE SET NULL,
                handled_at   TEXT,
                handled_note TEXT,
                created_at   TEXT    NOT NULL
            )
            SQL);

        $pdo->exec("CREATE INDEX idx_contact_open ON contact_messages(created_at DESC) WHERE status = 'offen'");
        $pdo->exec('CREATE INDEX idx_contact_created ON contact_messages(created_at DESC)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS contact_messages');
        $pdo->exec('DROP INDEX IF EXISTS idx_users_banned_until');
        $pdo->exec('ALTER TABLE users DROP COLUMN banned_by');
        $pdo->exec('ALTER TABLE users DROP COLUMN banned_reason');
        $pdo->exec('ALTER TABLE users DROP COLUMN banned_until');
        $pdo->exec('ALTER TABLE users DROP COLUMN banned_at');
    }
};
