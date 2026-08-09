#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Erzeugt die fehlenden Bildgroessen fuer Bestandsanzeigen.
 *
 * Seit Phase 11 liegt jedes Anzeigenbild in drei Breiten vor (400, 800, 1600);
 * die Trefferliste waehlt ueber srcset die passende. Bilder, die vorher
 * hochgeladen wurden, haben nur die grosse Fassung — dieser Befehl holt das
 * nach.
 *
 * Bewusst ein einmaliger Befehl und kein Auftrag im Request: Das Umrechnen
 * kostet je Bild spuerbar Rechenzeit, und es ist eine Nachholarbeit, keine
 * laufende Aufgabe. Solange er nicht gelaufen ist, liefert srcset nur die
 * vorhandenen Fassungen — die Seite bleibt richtig, nur eben nicht sparsam.
 *
 *   php bin/reimage.php            # rechnet, was fehlt
 *   php bin/reimage.php --pruefen  # zeigt nur, was fehlen wuerde
 *   php bin/reimage.php --alle     # rechnet auch vorhandene Fassungen neu
 *
 * Rueckgabewert 0 = fertig, 1 = mindestens ein Bild liess sich nicht umrechnen.
 */

use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Storage\ImageException;
use Reptilienmarkt\Infra\Storage\ImagePipeline;
use Reptilienmarkt\Infra\Storage\PublicImageStorage;
use Reptilienmarkt\Support\Container;

if (\PHP_SAPI !== 'cli') {
    exit("bin/reimage.php laeuft nur auf der Kommandozeile.\n");
}

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$argumente = array_slice($argv, 1);
$nurPruefen = in_array('--pruefen', $argumente, true);
$alle = in_array('--alle', $argumente, true);

$database = $container->get(Database::class);
$storage = $container->get(PublicImageStorage::class);
$pipeline = $container->get(ImagePipeline::class);

$bilder = $database->select("SELECT id, path FROM listing_media WHERE media_type = 'bild' ORDER BY id");

$gerechnet = 0;
$uebersprungen = 0;
$fehlend = 0;
$fehler = 0;

foreach ($bilder as $bild) {
    $pfad = (string) $bild['path'];
    $quelle = $storage->absolutePath($pfad);

    if (!is_file($quelle)) {
        // Eine fehlende Datei ist kein Fall fuer diesen Befehl: Sie kann ein
        // Einhaengeproblem sein, und dann waere Loeschen das Letzte, was hilft.
        ++$fehlend;
        printf("  [fehlt]  %s\n", $pfad);

        continue;
    }

    $ziele = [];

    foreach ($storage->variantTargets($pfad) as $breite => $ziel) {
        if ($ziel === $quelle) {
            // Die groesste Fassung ist die Quelle selbst. Sie nur bei --alle
            // neu zu schreiben verhindert, dass ein Abbruch mitten im Lauf das
            // Original durch eine halbe Datei ersetzt.
            if ($alle) {
                $ziele[$breite] = $ziel;
            }

            continue;
        }

        if ($alle || !is_file($ziel)) {
            $ziele[$breite] = $ziel;
        }
    }

    if ($ziele === []) {
        ++$uebersprungen;

        continue;
    }

    if ($nurPruefen) {
        printf("  [fehlt]  %s — %d Fassungen\n", $pfad, count($ziele));
        ++$gerechnet;

        continue;
    }

    try {
        $pipeline->processVariants($quelle, $ziele);
        ++$gerechnet;
        printf("  [neu]    %s — %d Fassungen\n", $pfad, count($ziele));
    } catch (ImageException $exception) {
        ++$fehler;
        printf("  [Fehler] %s — %s\n", $pfad, $exception->getMessage());
    }
}

printf(
    "\n%d Bilder: %d %s, %d vollstaendig, %d Dateien fehlen, %d Fehler.\n",
    count($bilder),
    $gerechnet,
    $nurPruefen ? 'offen' : 'gerechnet',
    $uebersprungen,
    $fehlend,
    $fehler,
);

exit($fehler > 0 ? 1 : 0);
