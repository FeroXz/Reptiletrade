<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Wann wurde welche Seite zuletzt ueber dieses Gespraech benachrichtigt.
     *
     * Zwei Spalten statt einer: An einem Gespraech haengen zwei Menschen, und
     * die Frage "habe ich dich in der letzten Stunde schon angeschrieben?" ist
     * fuer jeden von beiden eine andere. Eine gemeinsame Spalte wuerde die Mail
     * an den einen als Nachricht an den anderen zaehlen — mit dem Ergebnis,
     * dass jemand ueber eine Nachricht an ihn nie erfaehrt.
     *
     * Bewusst an conversations und nicht in einer eigenen Tabelle: Der Wert
     * wird nur zusammen mit dem Gespraech gelesen und geschrieben.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE conversations ADD COLUMN notified_buyer_at TEXT');
        $pdo->exec('ALTER TABLE conversations ADD COLUMN notified_seller_at TEXT');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE conversations DROP COLUMN notified_seller_at');
        $pdo->exec('ALTER TABLE conversations DROP COLUMN notified_buyer_at');
    }
};
