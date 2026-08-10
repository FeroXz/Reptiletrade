<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Der Abmeldelink wird abgeleitet statt gespeichert.
     *
     * Bisher stand in users.unsubscribe_token der SHA-256-Hash **eines**
     * Klartexttokens. Weil sich der Klartext aus dem Hash nicht rekonstruieren
     * laesst, musste jede Mail einen neuen Token ausstellen — und entwertete
     * damit den Abmeldelink jeder aelteren Mail. Ein Ein-Klick-Abmelden, das
     * nur in der zuletzt verschickten Mail funktioniert, ist bei Gmail und
     * Outlook ein Reputationsschaden.
     *
     * Statt eines Tokens haelt das Konto jetzt ein Geheimnis, aus dem sich der
     * Link jederzeit **reproduzieren** laesst (HMAC-SHA-256 ueber die
     * user_id, siehe NotificationPreferenceService). Damit gilt derselbe Link
     * in allen Mails, der Versand liest nur noch und schreibt nicht mehr in
     * users — und ein Wechsel des Geheimnisses entwertet weiterhin alle Links
     * des Kontos auf einen Schlag.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users ADD COLUMN unsubscribe_secret TEXT');
        $pdo->exec('ALTER TABLE users ADD COLUMN unsubscribe_secret_at TEXT');

        // Bestandskonten bekommen ihr Geheimnis hier und nicht beim ersten
        // Versand: Sonst waere der erste Mailversand jedes Kontos doch wieder
        // ein Schreibzugriff auf users. randomblob() zieht pro Zeile neu und
        // speist sich aus derselben Quelle wie random_bytes() (auf Unix
        // /dev/urandom) — fuer eine einmalige Nachruestung genuegt das; neue
        // Konten bekommen ihres in PdoUserRepository::create() aus PHP.
        $pdo->exec(
            <<<'SQL'
                UPDATE users
                   SET unsubscribe_secret = lower(hex(randomblob(32))),
                       unsubscribe_secret_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
                 WHERE unsubscribe_secret IS NULL
                SQL,
        );

        // Der alte Token faellt ersatzlos weg. Ihn stehen zu lassen hiesse,
        // einen zweiten, ungenutzten Weg ins Konto aufzubewahren.
        $pdo->exec('DROP INDEX IF EXISTS idx_users_unsubscribe_token');
        $pdo->exec('ALTER TABLE users DROP COLUMN unsubscribe_token_at');
        $pdo->exec('ALTER TABLE users DROP COLUMN unsubscribe_token');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users ADD COLUMN unsubscribe_token TEXT');
        $pdo->exec('ALTER TABLE users ADD COLUMN unsubscribe_token_at TEXT');
        $pdo->exec('CREATE UNIQUE INDEX idx_users_unsubscribe_token ON users(unsubscribe_token)');

        // Die alten Token kehren leer zurueck: Sie liessen sich aus dem
        // Geheimnis nicht rekonstruieren, und sie sollen es auch nicht.
        $pdo->exec('ALTER TABLE users DROP COLUMN unsubscribe_secret_at');
        $pdo->exec('ALTER TABLE users DROP COLUMN unsubscribe_secret');
    }
};
