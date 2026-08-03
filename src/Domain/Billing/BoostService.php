<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

use Reptilienmarkt\Domain\Audit\AuditActorType;
use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Support\Clock;

/**
 * Top-Platzierung auf Zeit.
 *
 * Die Suche sortiert nach listings.is_featured — dieser Dienst ist die einzige
 * Stelle, die dieses Feld setzt. Es bleibt denormalisiert, weil die
 * Trefferliste danach sortiert und ein Join auf die Boost-Tabelle den in
 * Phase 3 gemessenen Abfrageplan zerstoeren wuerde.
 *
 * Der Preis dieser Entscheidung: Das Feld muss aktiv wieder abgeraeumt werden.
 * Genau das macht expireDue(); im Betrieb ruft ein Job es auf (Phase 7), bis
 * dahin bin/boosts.php.
 */
final readonly class BoostService
{
    public function __construct(
        private BoostRepository $boosts,
        private ListingRepository $listings,
        private BillingConfiguration $config,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    /**
     * @return array<string, BoostOption>
     */
    public function options(): array
    {
        return $this->config->boosts();
    }

    public function option(string $key): ?BoostOption
    {
        return $this->config->boosts()[$key] ?? null;
    }

    /**
     * Schaltet einen Boost scharf. Wird erst nach bestaetigter Zahlung
     * aufgerufen — oder von der Moderation, wenn sie einen verschenkt.
     *
     * @throws BillingException
     */
    public function activate(int $listingId, string $boostKey, ?int $paymentId = null, ?int $actorId = null): Boost
    {
        $option = $this->option($boostKey);

        if ($option === null) {
            throw new BillingException(\sprintf('Die Top-Platzierung "%s" gibt es nicht.', $boostKey));
        }

        $listing = $this->listings->findById($listingId);
        if ($listing === null) {
            throw new BillingException('Die Anzeige gibt es nicht mehr.');
        }

        $now = $this->clock->now();
        $laufend = $this->boosts->activeForListing($listingId, $now);

        // Ein zweiter Boost verlaengert den laufenden, statt ihn zu ersetzen —
        // sonst verfaellt bezahlte Zeit.
        $start = $laufend === null ? $now : $laufend->endsAt;

        $boost = new Boost(
            null,
            $listingId,
            $boostKey,
            $start,
            $option->endsAt($start),
            $paymentId,
            $now,
        );

        $id = $this->boosts->save($boost);
        $this->listings->setFeatured($listingId, true);

        $this->audit->record(new AuditEntry(
            'boost.activated',
            'listing',
            $listingId,
            ['boost' => $boostKey, 'tage' => $option->days, 'payment_id' => $paymentId],
            $actorId,
        ));

        return $this->boosts->findById($id) ?? $boost;
    }

    /**
     * Nimmt abgelaufene Boosts aus der Hervorhebung.
     *
     * @return int Anzahl der zurueckgesetzten Anzeigen
     */
    public function expireDue(int $limit = 200): int
    {
        $now = $this->clock->now();
        $zurueckgesetzt = 0;

        foreach ($this->boosts->dueForExpiry($now, $limit) as $boost) {
            // Ein Nachfolgeboost kann laengst laufen — dann bleibt die Anzeige
            // hervorgehoben.
            if ($this->boosts->activeForListing($boost->listingId, $now) !== null) {
                continue;
            }

            $this->listings->setFeatured($boost->listingId, false);
            ++$zurueckgesetzt;

            $this->audit->record(new AuditEntry(
                'boost.expired',
                'listing',
                $boost->listingId,
                ['boost' => $boost->boostKey],
                null,
                AuditActorType::System,
            ));
        }

        return $zurueckgesetzt;
    }

    public function activeFor(int $listingId): ?Boost
    {
        return $this->boosts->activeForListing($listingId, $this->clock->now());
    }
}
