<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Revisionssicherer Verlauf: Moderationsentscheidungen, Rechtsentscheidungen
        // der LegalGuard und jeder Statuswechsel einer Anzeige.
        //
        // actor_user_id traegt bewusst KEINEN Fremdschluessel: Eine ON-DELETE-Aktion
        // wuerde den Append-only-Trigger ausloesen und das Loeschen des Nutzers
        // blockieren. Der Audit-Trail haelt die ID auch nach Anonymisierung des Kontos.
        $pdo->exec(<<<'SQL'
            CREATE TABLE audit_log (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                occurred_at   TEXT    NOT NULL,
                actor_type    TEXT    NOT NULL DEFAULT 'user' CHECK (actor_type IN ('user','system','admin')),
                actor_user_id INTEGER,
                action        TEXT    NOT NULL,                                            -- z. B. "listing.status_changed"
                entity_type   TEXT    NOT NULL,                                            -- z. B. "listing"
                entity_id     INTEGER,
                data_json     TEXT    NOT NULL DEFAULT '{}' CHECK (json_valid(data_json)), -- Gruende der LegalDecision u. a.
                ip_address    TEXT
            )
            SQL);

        $pdo->exec('CREATE INDEX idx_audit_log_entity ON audit_log(entity_type, entity_id, occurred_at DESC)');
        $pdo->exec('CREATE INDEX idx_audit_log_actor ON audit_log(actor_user_id, occurred_at DESC)');
        $pdo->exec('CREATE INDEX idx_audit_log_action ON audit_log(action, occurred_at DESC)');

        // Append-only: Die Datenbank verweigert Aenderungen und Loeschungen.
        $pdo->exec(<<<'SQL'
            CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log
            BEGIN
                SELECT RAISE(ABORT, 'audit_log ist append-only');
            END
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log
            BEGIN
                SELECT RAISE(ABORT, 'audit_log ist append-only');
            END
            SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TRIGGER IF EXISTS audit_log_no_delete');
        $pdo->exec('DROP TRIGGER IF EXISTS audit_log_no_update');
        $pdo->exec('DROP TABLE IF EXISTS audit_log');
    }
};
