#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Plant die wiederkehrenden Aufgaben ein.
 *
 * Ein einziger Cron-Eintrag reicht — der Zeitplan steht in JobScheduler und
 * damit im Code, nicht in der Crontab:
 *
 *   *\/15 * * * * php /pfad/bin/cron.php
 *
 * Viertelstuendlich, nicht stuendlich: Ein geplanter Beitrag soll nicht bis zu
 * einer Stunde zu spaet erscheinen. Die stuendlichen und taeglichen Aufgaben
 * werden deswegen nicht oefter eingeplant — JobScheduler kennt den Abstand.
 *
 * Ausgefuehrt werden die Auftraege danach von bin/worker.php.
 *
 *   php bin/cron.php              faellige Aufgaben einplanen
 *   php bin/cron.php plan         den Zeitplan anzeigen
 *   php bin/cron.php jetzt TYP    einen Auftrag von Hand einplanen
 */

use Reptilienmarkt\Domain\Job\JobException;
use Reptilienmarkt\Domain\Job\JobInterval;
use Reptilienmarkt\Domain\Job\JobScheduler;
use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Log\Logger;

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$scheduler = $container->get(JobScheduler::class);
$logger = $container->get(Logger::class);
$befehl = $argv[1] ?? 'einplanen';

switch ($befehl) {
    case 'plan':
        echo "Zeitplan (Stunde in UTC):\n";

        foreach ($scheduler->plan() as $type => $takt) {
            printf(
                "  %-24s %s\n",
                $type,
                $takt instanceof JobInterval ? $takt->label() : sprintf('%02d:00', $takt),
            );
        }

        break;

    case 'jetzt':
        $type = $argv[2] ?? '';

        try {
            $id = $scheduler->enqueueOnce($type);
            printf("Auftrag %s eingeplant (#%d).\n", $type, $id);
        } catch (JobException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            exit(1);
        }

        break;

    case 'einplanen':
        $eingeplant = $scheduler->schedule();

        if ($eingeplant === []) {
            echo "Nichts fällig.\n";

            break;
        }

        $logger->info('cron.scheduled', ['auftraege' => $eingeplant]);
        printf("Eingeplant: %s\n", implode(', ', $eingeplant));

        break;

    default:
        fwrite(\STDERR, "Unbekannter Befehl. Erlaubt: einplanen, plan, jetzt\n");

        exit(1);
}
