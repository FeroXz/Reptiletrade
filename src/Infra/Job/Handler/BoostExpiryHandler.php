<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Job\Handler;

use Reptilienmarkt\Domain\Billing\BillingService;
use Reptilienmarkt\Domain\Billing\BoostService;
use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobHandler;

/**
 * Raeumt abgelaufene Top-Platzierungen und Abos ab.
 *
 * Der Gegenpart zur denormalisierten Hervorhebung aus Phase 6: Weil die Suche
 * nach listings.is_featured sortiert und nicht joint, muss das Feld aktiv
 * zurueckgesetzt werden.
 */
final readonly class BoostExpiryHandler implements JobHandler
{
    public function __construct(
        private BoostService $boosts,
        private BillingService $billing,
    ) {}

    public function type(): string
    {
        return 'billing.expire';
    }

    public function handle(Job $job): string
    {
        $boosts = $this->boosts->expireDue();
        $abos = $this->billing->expireDueSubscriptions();

        return \sprintf('%d Top-Platzierungen, %d Abos abgelaufen', $boosts, $abos);
    }
}
