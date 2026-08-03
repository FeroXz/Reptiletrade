#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Wiederkehrende Aufgaben der Abrechnung.
 *
 * Diese Aufgaben gehoeren in die Job-Tabelle — die kommt in Phase 7. Bis dahin
 * laufen sie ueber diesen Aufruf, etwa aus einem Cron-Eintrag. Der Inhalt
 * aendert sich dabei nicht, nur der Ausloeser.
 *
 *   php bin/billing.php ablauf     Abgelaufene Boosts und Abos aufraeumen
 *   php bin/billing.php status     Uebersicht ohne Aenderung
 */

use Reptilienmarkt\Domain\Billing\BillingService;
use Reptilienmarkt\Domain\Billing\BoostService;
use Reptilienmarkt\Domain\Billing\EntitlementService;
use Reptilienmarkt\Support\Container;

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$befehl = $argv[1] ?? 'status';

$billing = $container->get(BillingService::class);
$boosts = $container->get(BoostService::class);
$entitlements = $container->get(EntitlementService::class);

switch ($befehl) {
    case 'ablauf':
        $abgeraeumt = $boosts->expireDue();
        $abgelaufen = $billing->expireDueSubscriptions();

        printf("Top-Platzierungen zurueckgesetzt: %d\n", $abgeraeumt);
        printf("Abos auf abgelaufen gesetzt:      %d\n", $abgelaufen);

        break;

    case 'status':
        printf("Monetarisierung: %s\n", $billing->enabled() ? 'aktiv' : 'vorbereitet, nicht aktiviert');
        printf("Zahlungsanbieter: %s%s\n", $billing->providerName(), $billing->purchasable() ? '' : ' (nicht einsatzbereit)');
        echo "\nTarife:\n";

        foreach ($entitlements->plans() as $key => $plan) {
            printf(
                "  %-14s %-22s %10s %s\n",
                $key,
                $plan->name,
                $plan->isFree() ? 'kostenlos' : $plan->price->format(),
                $plan->maxActiveListings() === null ? 'unbegrenzt' : $plan->maxActiveListings() . ' Anzeigen',
            );
        }

        echo "\nTop-Platzierungen:\n";

        foreach ($boosts->options() as $key => $option) {
            printf("  %-10s %-28s %10s\n", $key, $option->name, $option->price->format());
        }

        break;

    default:
        fwrite(\STDERR, "Unbekannter Befehl. Erlaubt: ablauf, status\n");

        exit(1);
}
