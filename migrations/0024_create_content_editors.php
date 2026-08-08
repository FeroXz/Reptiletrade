<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Wer die Redaktion bedienen darf.
     *
     * Eine eigene Tabelle statt eines neuen Werts in users.role: Die Spalte
     * traegt seit 0001 einen CHECK-Constraint, und SQLite kann den nur ueber
     * den Neubau der Tabelle aendern. Ein Neubau der Kontentabelle fuer eine
     * Berechtigung waere ein hoher Preis — und sein down-Pfad muesste alle
     * Redakteure auf einen anderen Wert zuruecksetzen, also Daten verlieren.
     *
     * So bleibt users unangetastet, der down-Pfad ist ein DROP TABLE, und die
     * einzige Frage, die die Controller stellen, beantwortet ein Index-Treffer:
     * "Darf dieser Nutzer die Redaktion bedienen?" — wahr fuer admin und fuer
     * jeden Eintrag hier.
     *
     * Die Begruendung im Zusammenhang steht in docs/CMS.md unter E1.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE content_editors (
                user_id    INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                granted_at TEXT    NOT NULL,
                -- Wer die Berechtigung erteilt hat. Kein CASCADE: Loescht sich
                -- der Erteilende, bleibt die Berechtigung — nur ihre Herkunft
                -- ist dann nicht mehr benannt.
                granted_by INTEGER REFERENCES users(id) ON DELETE SET NULL
            ) WITHOUT ROWID
            SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS content_editors');
    }
};
