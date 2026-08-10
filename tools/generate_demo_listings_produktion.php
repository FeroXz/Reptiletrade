#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Beispielanzeigen — ausdruecklich auch im Produktivbetrieb.
 *
 * tools/generate_demo_listings.php bleibt in APP_ENV=production gesperrt. Wer
 * ein frisch aufgesetztes Schaufenster fuellen will, nimmt dieses Werkzeug:
 * Es laeuft in jeder Umgebung, arbeitet in Stapeln, damit die Datenbank
 * zwischendurch fuer echte Besucher frei ist, und raeumt mit --entfernen
 * restlos wieder ab.
 *
 *   php tools/generate_demo_listings_produktion.php --anzahl=50000
 *   php tools/generate_demo_listings_produktion.php --entfernen
 *
 * Die Anzeigen sind erfunden: kein Tier dahinter, keine Rechtsnachweise, kein
 * Verkaeufer, der auf eine Nachricht antwortet. Sie tragen "Beispielanzeige"
 * im Titel und einen Hinweis in der Beschreibung. Was ein Marktplatz mit
 * erfundenen Angeboten rechtlich bedeutet, entscheidet der Betreiber — dieses
 * Werkzeug nimmt ihm die Entscheidung nicht ab, es fuehrt sie nur aus.
 */

use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Demo\DemoDataException;
use Reptilienmarkt\Support\Demo\DemoIndexStrategy;
use Reptilienmarkt\Support\Demo\DemoListingGenerator;
use Reptilienmarkt\Support\Env;

if (\PHP_SAPI !== 'cli') {
    exit("Nur auf der Kommandozeile.\n");
}

$root = dirname(__DIR__);

/** @var Container $container */
$container = require $root . '/config/bootstrap.php';

/** @var list<string> $argv */
$argv = $argv ?? [];

if (in_array('--help', $argv, true) || in_array('--hilfe', $argv, true)) {
    echo <<<'TEXT'
        Verwendung: php tools/generate_demo_listings_produktion.php [optionen]

        Legt Beispielanzeigen an — auch wenn APP_ENV=production gesetzt ist.

        Optionen:
          --anzahl=N       Wie viele Anzeigen (Vorgabe 50000)
          --stapel=N       Anzeigen je Transaktion (Vorgabe 500). Kleiner = kuerzere
                           Schreibsperren, laengere Gesamtlaufzeit.
          --bilder=ORDNER  Ordner unterhalb von STORAGE_PUBLIC mit Beispielbildern
                           (Vorgabe "demo"). Fehlt er, entstehen Anzeigen ohne Bild:
                           die Kachel zeigt dann "Kein Bild" statt eines toten Links.
          --ohne-bilder    Auch dann keine Bildzeilen anlegen, wenn der Ordner existiert
          --zufall=N       Startwert des Zufallsgenerators (Vorgabe 20260802)
          --entfernen      Alle Beispieldaten wieder loeschen und nichts anlegen
          --bestand        Nur zeigen, was gerade an Beispieldaten liegt
          --help           Diese Hilfe

        Die Beispieldaten haengen an den Konten demo001..demo200@example.tld.
        An ihnen meldet sich niemand an (der Passwort-Hash ist keiner), und
        --entfernen loescht genau diese Konten samt ihrer Anzeigen, Merkmale,
        Bilder und Suchindexeintraege. Echte Daten bleiben unberuehrt.

        TEXT;
    exit(0);
}

$anzahl = 50000;
$stapel = DemoListingGenerator::DEFAULT_BATCH_SIZE;
$zufall = 20260802;
$bilderOrdner = 'demo';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--anzahl=')) {
        $anzahl = max(1, (int) substr($argument, strlen('--anzahl=')));
    }

    if (str_starts_with($argument, '--stapel=')) {
        $stapel = max(1, (int) substr($argument, strlen('--stapel=')));
    }

    if (str_starts_with($argument, '--zufall=')) {
        $zufall = (int) substr($argument, strlen('--zufall='));
    }

    if (str_starts_with($argument, '--bilder=')) {
        $bilderOrdner = trim(substr($argument, strlen('--bilder=')), '/');
    }
}

$umgebung = Env::string('APP_ENV', 'production');
$generator = $container->get(DemoListingGenerator::class);
$bestand = $generator->inventory();

printf("Beispielanzeigen — Umgebung: %s\n", $umgebung === '' ? 'unbekannt' : $umgebung);
printf("Bestand: %d Beispielkonten, %d Beispielanzeigen\n\n", $bestand['users'], $bestand['listings']);

if (in_array('--bestand', $argv, true)) {
    exit(0);
}

if (in_array('--entfernen', $argv, true)) {
    echo "Entferne Beispieldaten ...\n";

    $bericht = $generator->remove($stapel);

    printf(
        "  %d Anzeigen und %d Konten geloescht in %.1f s.\n",
        $bericht->listings,
        $bericht->users,
        $bericht->seconds,
    );

    exit(0);
}

// Bildzeilen nur, wenn die Dateien wirklich liegen: Eine Kachel ohne Bild
// sieht ordentlich aus, eine Kachel mit totem Bildverweis nicht.
$bilder = [];
if (!in_array('--ohne-bilder', $argv, true) && $bilderOrdner !== '') {
    $uploads = $root . '/' . ltrim(Env::string('STORAGE_PUBLIC', 'public/uploads'), '/');

    // Je Endung ein eigener Aufruf statt GLOB_BRACE: Die Klammererweiterung
    // gibt es nicht auf jeder Plattform, und ein stiller Fehlschlag hier waere
    // eine Trefferliste voller toter Bildverweise.
    foreach (['webp', 'jpg', 'jpeg', 'png'] as $endung) {
        $treffer = glob($uploads . '/' . $bilderOrdner . '/*.' . $endung);

        foreach ($treffer === false ? [] : $treffer as $datei) {
            $bilder[] = $bilderOrdner . '/' . basename($datei);
        }
    }

    sort($bilder);
}

if ($umgebung === 'production') {
    echo "  Achtung: Diese Anzeigen sind erfunden und werden oeffentlich sichtbar.\n";
    echo "  Zurueck geht es mit: php tools/generate_demo_listings_produktion.php --entfernen\n\n";
}

printf("Lege %d Anzeigen an (Stapel: %d, Bilder: %s) ...\n", $anzahl, $stapel, $bilder === [] ? 'keine' : count($bilder) . ' Motive');

$terminal = stream_isatty(\STDOUT);
$letzterSchritt = 0;

$fortschritt = static function (int $erledigt, int $gesamt) use ($terminal, &$letzterSchritt): void {
    $schritt = intdiv($erledigt * 20, max(1, $gesamt));

    if ($terminal) {
        printf("\r  %d / %d", $erledigt, $gesamt);

        if ($erledigt >= $gesamt) {
            echo "\n";
        }

        return;
    }

    // Ohne Terminal (Cron, Protokolldatei) nur alle fuenf Prozent eine Zeile.
    if ($schritt > $letzterSchritt) {
        $letzterSchritt = $schritt;
        printf("  %d / %d\n", $erledigt, $gesamt);
    }
};

try {
    $bericht = $generator->generate(
        count: $anzahl,
        strategy: DemoIndexStrategy::Inkrementell,
        batchSize: $stapel,
        mediaPaths: $bilder,
        seed: $zufall,
        progress: $fortschritt,
    );
} catch (DemoDataException $exception) {
    fwrite(\STDERR, $exception->getMessage() . "\n");

    exit(1);
}

printf(
    "\n  %d Anzeigen, %d Merkmale, %d Bilder, %d neue Konten in %.1f s.\n",
    $bericht->listings,
    $bericht->morphs,
    $bericht->media,
    $bericht->users,
    $bericht->seconds,
);
printf("  %d Anzeigen in den Volltextindex nachgetragen.\n", $bericht->indexed);

echo "\n  Der Index wurde nur ergaenzt, nicht neu gebaut — vorhandene Anzeigen blieben\n";
echo "  unberuehrt. Wer den Index danach zusammenfassen will: php bin/reindex.php\n";
echo "  Zurueckbauen: php tools/generate_demo_listings_produktion.php --entfernen\n";

exit(0);
