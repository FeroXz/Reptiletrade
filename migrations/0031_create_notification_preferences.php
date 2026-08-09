<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Benachrichtigungseinstellungen und der Abmeldelink.
     *
     * Die Tabelle traegt nur die **Abweichungen** von der Voreinstellung. Wer
     * nie etwas eingestellt hat, hat keine Zeile — und bekommt das, was
     * NotificationChannel als Vorgabe kennt. Das spart nicht nur Zeilen: Aendert
     * sich die Vorgabe eines Kanals, gilt sie sofort fuer alle, die sich nie
     * damit befasst haben, und eben nicht fuer die, die bewusst abgeschaltet
     * haben.
     *
     * users.unsubscribe_token haelt — wie alle Token im Bestand — nur den
     * SHA-256-Hash. Der Klartext steht ausschliesslich im Abmeldelink der
     * zuletzt verschickten Mail; dass ein neuer Link den vorigen entwertet, ist
     * die Folge davon und in NotificationPreferenceService begruendet.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE notification_preferences (
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                channel_key TEXT    NOT NULL CHECK (channel_key <> ''),
                enabled     INTEGER NOT NULL CHECK (enabled IN (0, 1)),
                updated_at  TEXT    NOT NULL,
                PRIMARY KEY (user_id, channel_key)
            ) WITHOUT ROWID
            SQL);

        $pdo->exec('ALTER TABLE users ADD COLUMN unsubscribe_token TEXT');
        $pdo->exec('ALTER TABLE users ADD COLUMN unsubscribe_token_at TEXT');

        // Der Abmeldelink wird ueber diesen Hash nachgeschlagen — ohne Index
        // waere jeder Klick ein Tabellendurchlauf. Eindeutig, weil zwei Konten
        // sich denselben Token teilen wuerden: Mehrere NULL nimmt SQLite in
        // einem UNIQUE-Index hin, zwei gleiche Werte nicht.
        $pdo->exec('CREATE UNIQUE INDEX idx_users_unsubscribe_token ON users(unsubscribe_token)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS notification_preferences');
        $pdo->exec('DROP INDEX IF EXISTS idx_users_unsubscribe_token');
        $pdo->exec('ALTER TABLE users DROP COLUMN unsubscribe_token_at');
        $pdo->exec('ALTER TABLE users DROP COLUMN unsubscribe_token');
    }
};
