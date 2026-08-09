<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Zusaetzliche Kopfzeilen im Postausgang.
     *
     * Anlass ist List-Unsubscribe: Der Kopf muss beim Transport ankommen, und
     * zwischen Einreihen und Zustellen liegt die Tabelle. Ihn beim Zustellen
     * neu zu berechnen ginge nicht — der Abmeldetoken gehoert zu genau dieser
     * Mail und wird beim Einreihen ausgestellt.
     *
     * Bewusst eine JSON-Spalte und keine eigene Tabelle: Kopfzeilen werden nie
     * einzeln abgefragt, sondern immer als Ganzes mit der Mail gelesen.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE mail_outbox ADD COLUMN headers_json TEXT NOT NULL DEFAULT '{}'");
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE mail_outbox DROP COLUMN headers_json');
    }
};
