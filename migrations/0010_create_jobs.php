<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        // Job-Tabelle statt Message-Broker. bin/worker.php reserviert Jobs atomar
        // ueber (status, available_at) und wird per systemd-Timer oder Cron getriggert.
        $pdo->exec(<<<'SQL'
            CREATE TABLE jobs (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                queue        TEXT    NOT NULL DEFAULT 'default',
                type         TEXT    NOT NULL,                                             -- z. B. "listing.expiry_notice"
                payload_json TEXT    NOT NULL DEFAULT '{}' CHECK (json_valid(payload_json)),
                status       TEXT    NOT NULL DEFAULT 'wartend'
                                     CHECK (status IN ('wartend','laeuft','erledigt','fehlgeschlagen')),
                attempts     INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 3,
                available_at TEXT    NOT NULL,                                             -- fruehester Ausfuehrungszeitpunkt
                reserved_at  TEXT,
                reserved_by  TEXT,                                                         -- Worker-Kennung
                completed_at TEXT,
                last_error   TEXT,
                created_at   TEXT    NOT NULL
            )
            SQL);

        $pdo->exec("CREATE INDEX idx_jobs_claim ON jobs(queue, available_at) WHERE status = 'wartend'");
        $pdo->exec('CREATE INDEX idx_jobs_status ON jobs(status, created_at)');
        $pdo->exec('CREATE INDEX idx_jobs_type ON jobs(type, status)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS jobs');
    }
};
