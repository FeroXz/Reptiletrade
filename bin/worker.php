#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Arbeitet die Auftragstabelle ab.
 *
 * Aufruf per systemd-Timer oder Cron. Bewusst kein Dauerlaeufer: Ein Prozess,
 * der Wochen laeuft, sammelt Speicher, haelt eine alte Codefassung fest und
 * faellt irgendwann unbemerkt aus. Ein Aufruf je Minute erledigt dasselbe und
 * startet nach jedem Deployment von selbst mit dem neuen Stand.
 *
 *   php bin/worker.php [--max=50] [--queue=default] [--einmal]
 *
 * systemd-Timer (empfohlen):
 *   OnUnitActiveSec=1min, Type=oneshot
 */

use Reptilienmarkt\Domain\Job\JobRunner;
use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Log\Logger;

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$optionen = getopt('', ['max::', 'queue::', 'einmal']);
$max = isset($optionen['max']) && is_string($optionen['max']) ? max(1, (int) $optionen['max']) : 50;
$queue = isset($optionen['queue']) && is_string($optionen['queue']) ? $optionen['queue'] : 'default';

$runner = $container->get(JobRunner::class);
$logger = $container->get(Logger::class);

// Verwaiste Auftraege zuerst: Sie blockieren sonst, bis jemand hinsieht.
$freigegeben = $runner->releaseStale();

if ($freigegeben > 0) {
    $logger->warning('worker.released_stale', ['anzahl' => $freigegeben]);
}

$start = microtime(true);
$bilanz = $runner->run($max, $queue);
$dauer = round((microtime(true) - $start) * 1000, 1);

$logger->info('worker.run', $bilanz + ['queue' => $queue, 'dauer_ms' => $dauer]);

printf(
    "Queue %s: %d erledigt, %d wiederholt, %d fehlgeschlagen (%.1f ms)\n",
    $queue,
    $bilanz['erledigt'],
    $bilanz['wiederholt'],
    $bilanz['fehlgeschlagen'],
    $dauer,
);

// Fehlgeschlagene Auftraege sind ein Betriebsvorfall, kein Erfolg.
exit($bilanz['fehlgeschlagen'] > 0 ? 1 : 0);
