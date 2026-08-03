#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Sicherung der SQLite-Datenbank.
 *
 * Ueber VACUUM INTO, nicht ueber cp: Eine Dateikopie im laufenden Betrieb
 * erwischt die Datenbank mitten in einer Transaktion und liefert im WAL-Modus
 * eine Datei ohne das zugehoerige Write-Ahead-Log — die Sicherung ist dann
 * still unbrauchbar und faellt erst beim Zurueckspielen auf.
 *
 * VACUUM INTO dagegen schreibt einen in sich stimmigen Stand, ohne Schreiber
 * zu blockieren, und raeumt die Datei dabei auf.
 *
 *   php bin/backup.php [--ziel=/pfad] [--behalten=14]
 */

use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Env;
use Reptilienmarkt\Support\Log\Logger;

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$optionen = getopt('', ['ziel::', 'behalten::']);
$root = dirname(__DIR__);
$ziel = isset($optionen['ziel']) && is_string($optionen['ziel'])
    ? rtrim($optionen['ziel'], '/')
    : $root . '/' . ltrim(Env::string('BACKUP_DIRECTORY', 'storage/backups'), '/');
$behalten = isset($optionen['behalten']) && is_string($optionen['behalten'])
    ? max(1, (int) $optionen['behalten'])
    : Env::int('BACKUP_KEEP', 14);

if (!is_dir($ziel) && !mkdir($ziel, 0o770, true) && !is_dir($ziel)) {
    fwrite(\STDERR, "Sicherungsverzeichnis liess sich nicht anlegen: {$ziel}\n");

    exit(1);
}

$database = $container->get(Database::class);
$logger = $container->get(Logger::class);

// VACUUM INTO lehnt eine bestehende Datei ab — zu Recht. Zwei Laeufe in
// derselben Sekunde bekommen deshalb einen Zusatz statt einer ueberschriebenen
// Sicherung: Eine stillschweigend ersetzte Kopie waere genau die, die im
// Ernstfall fehlt.
$stempel = gmdate('Ymd-His');
$datei = sprintf('%s/reptilienmarkt-%s.sqlite', $ziel, $stempel);

// Unterstrich statt Bindestrich, damit der Zusatz beim Sortieren nach dem
// Namen hinter der Sekunde landet und nicht davor — sonst gaelte die neuere
// Kopie beim Aufraeumen als die aeltere.
for ($lauf = 2; is_file($datei); ++$lauf) {
    $datei = sprintf('%s/reptilienmarkt-%s_%d.sqlite', $ziel, $stempel, $lauf);
}

$start = microtime(true);

try {
    $database->pdo()->exec(sprintf("VACUUM INTO %s", $database->pdo()->quote($datei)));
} catch (PDOException $exception) {
    $logger->error('backup.failed', ['fehler' => $exception->getMessage()]);
    fwrite(\STDERR, 'Sicherung fehlgeschlagen: ' . $exception->getMessage() . "\n");

    exit(1);
}

chmod($datei, 0o600);

$groesse = filesize($datei);
$dauer = round((microtime(true) - $start) * 1000, 1);

// Erst nach erfolgreicher Sicherung aufraeumen: Sonst loescht ein
// fehlgeschlagener Lauf die letzte brauchbare Kopie.
//
// Sortiert wird nach Aenderungszeit, nicht nach Namen: Der Name kann bei zwei
// Laeufen in derselben Sekunde einen Zusatz tragen, und dann stimmt die
// alphabetische Reihenfolge nicht mehr mit dem Alter ueberein. Die eben
// geschriebene Datei ist ausgenommen — sie darf ihr eigener Lauf nicht
// wegraeumen.
$vorhandene = array_values(array_filter(
    glob($ziel . '/reptilienmarkt-*.sqlite') ?: [],
    static fn(string $pfad): bool => $pfad !== $datei,
));
usort($vorhandene, static fn(string $a, string $b): int => filemtime($a) <=> filemtime($b));
$zuAlt = array_slice($vorhandene, 0, max(0, count($vorhandene) + 1 - $behalten));

foreach ($zuAlt as $alte) {
    unlink($alte);
}

$logger->info('backup.completed', [
    'datei' => basename($datei),
    'bytes' => $groesse === false ? 0 : $groesse,
    'dauer_ms' => $dauer,
    'geloescht' => count($zuAlt),
]);

printf(
    "Sicherung: %s (%.1f MB, %.1f ms), %d alte entfernt, %d behalten\n",
    basename($datei),
    ($groesse === false ? 0 : $groesse) / 1024 / 1024,
    $dauer,
    count($zuAlt),
    count($vorhandene) - count($zuAlt) + 1,
);
