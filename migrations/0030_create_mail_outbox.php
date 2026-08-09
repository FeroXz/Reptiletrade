<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Der Postausgang.
     *
     * Bis hierher rief jeder Versand den Mailer mitten im Request auf. Das hat
     * zwei Fehler, die sich nicht wegkonfigurieren lassen: Ein langsamer MTA
     * haengt die Registrierung an seiner Antwortzeit auf, und ein Fehlschlag
     * ist unwiederbringlich weg — niemand erfaehrt, dass die
     * Bestaetigungsmail nie ankam.
     *
     * Mit der Tabelle wird der Versand zweigeteilt: Der Request schreibt nur
     * eine Zeile (schnell, transaktional, wiederholbar), der Worker stellt zu.
     * Ein toter Transport kostet dann Zustellzeit, aber keinen Vorgang.
     *
     * "purpose" haelt fest, wozu die Mail gehoert (Kontobestaetigung,
     * Kontaktformular, Ablauferinnerung). Das ist die Grundlage fuer die
     * Benachrichtigungseinstellungen und macht den Postausgang lesbar, ohne
     * den Text zu lesen — im Betrieb schaut man auf Zeilen, nicht auf Inhalte.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE mail_outbox (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                recipient      TEXT    NOT NULL CHECK (recipient <> ''),
                recipient_name TEXT,
                subject        TEXT    NOT NULL,
                body           TEXT    NOT NULL,
                purpose        TEXT    NOT NULL DEFAULT 'allgemein',
                user_id        INTEGER REFERENCES users(id) ON DELETE SET NULL,
                status         TEXT    NOT NULL DEFAULT 'wartend'
                                       CHECK (status IN ('wartend', 'gesendet', 'fehlgeschlagen')),
                attempts       INTEGER NOT NULL DEFAULT 0,
                last_error     TEXT,
                created_at     TEXT    NOT NULL,
                updated_at     TEXT    NOT NULL,
                sent_at        TEXT
            )
            SQL);

        // Der Worker fragt nur nach dem, was noch aussteht, und arbeitet in
        // Eingangsreihenfolge ab. Alles andere waere unfair gegenueber der
        // aeltesten Mail — und die ist die, auf die jemand wartet.
        $pdo->exec('CREATE INDEX idx_mail_outbox_offen ON mail_outbox(status, id)');

        // Auskunft und Kontoloeschung fragen nach Konto, nicht nach Status.
        $pdo->exec('CREATE INDEX idx_mail_outbox_user ON mail_outbox(user_id)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS mail_outbox');
    }
};
