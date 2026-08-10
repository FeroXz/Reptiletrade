<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\Migration;

return new class implements Migration {
    /**
     * Welche Bildbreiten es gibt, steht jetzt in der Zeile.
     *
     * PublicImageStorage::srcset() hat bisher fuer jede der drei Breiten
     * is_file() gerufen. Bei 24 Treffern sind das 72 Dateisystemzugriffe je
     * Trefferliste — fuer eine Frage, deren Antwort sich nur beim Upload und
     * beim einmaligen Nachrechnen aendert. Dieses Projekt beantwortet solche
     * Fragen mit Denormalisierung; listings.is_featured steht aus demselben
     * Grund dort, wo es steht.
     *
     * Als sortierte, kommagetrennte Liste ('400,800,1600') und nicht als JSON:
     * Der Wert wird nie einzeln abgefragt, nur ausgegeben. NULL heisst "noch
     * nicht erzeugt" — dann bleibt das srcset leer und das img faellt auf sein
     * src zurueck, genau wie vorher bei fehlenden Dateien.
     *
     * Bewusst ohne Nachtrag fuer Bestandszeilen: Was auf der Platte liegt,
     * weiss nur die Platte, und eine Migration soll sie nicht durchsuchen. Das
     * ist die Aufgabe von bin/reimage.php, das ohnehin fuer diesen Fall da ist.
     */
    public function up(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE listing_media ADD COLUMN variant_widths TEXT');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE listing_media DROP COLUMN variant_widths');
    }
};
