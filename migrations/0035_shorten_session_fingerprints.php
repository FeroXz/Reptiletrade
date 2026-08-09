<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Raeumt weg, was schon in sessions steht.
     *
     * Die Spalten ip_address und user_agent gibt es seit Migration 0001; neu
     * ist, dass sie **gekuerzt** geschrieben werden. Ohne diesen Schritt laegen
     * die vollstaendigen Werte bestehender Sitzungen noch bis zu 30 Tage in der
     * Tabelle — genau die Aufbewahrung, die abgeschafft werden soll.
     *
     * Geleert statt nachtraeglich gekuerzt: Eine gekuerzte Adresse in SQL aus
     * einer vollstaendigen zu rechnen ginge nur mit Zeichenkettenakrobatik, die
     * bei IPv6 falsch liegt. Und es ist gar nicht noetig — die naechste Anfrage
     * jeder lebenden Sitzung schreibt beide Werte ohnehin neu, dann gekuerzt.
     * Bis dahin fehlt in der Geraeteliste eine Zeile Beiwerk; das ist der
     * billigere Preis.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec('UPDATE sessions SET ip_address = NULL, user_agent = NULL');
    }

    /**
     * Kein Weg zurueck: Was hier verworfen wurde, war der Zweck der Migration.
     * Eine Ruecknahme koennte die vollstaendigen Werte nicht wiederherstellen
     * und soll es auch nicht.
     */
    public function down(PDO $pdo): void {}
};
