<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

/**
 * Was ein Konto darf.
 *
 * Der Rest der Anwendung fragt ausschliesslich hier — nie nach dem Tarif und
 * schon gar nicht nach dem Abo. Damit bleibt die Antwort an einer Stelle,
 * wenn die Monetarisierung spaeter angeschaltet wird.
 *
 * Bei abgeschalteter Monetarisierung ist alles erlaubt und unbegrenzt. Das ist
 * kein Sonderfall im Code, sondern der Normalfall: Genau so laeuft die
 * Plattform heute.
 */
final readonly class Entitlements
{
    /**
     * @param list<Feature> $features
     */
    public function __construct(
        public bool $billingEnabled,
        public Plan $plan,
        public ?Subscription $subscription,
        public ?int $maxActiveListings,
        public int $runtimeDays,
        public int $maxImages,
        public array $features,
    ) {}

    public function has(Feature $feature): bool
    {
        // Ohne Abrechnung gibt es keine kostenpflichtigen Merkmale, sondern
        // nur Merkmale.
        return !$this->billingEnabled || \in_array($feature, $this->features, true);
    }

    public function unlimitedListings(): bool
    {
        return $this->maxActiveListings === null;
    }

    /**
     * Darf noch eine Anzeige veroeffentlicht werden?
     */
    public function mayPublish(int $activeListings): bool
    {
        return $this->maxActiveListings === null || $activeListings < $this->maxActiveListings;
    }

    public function remainingListings(int $activeListings): ?int
    {
        return $this->maxActiveListings === null ? null : max(0, $this->maxActiveListings - $activeListings);
    }

    public function hasSubscription(): bool
    {
        return $this->subscription !== null;
    }

    /**
     * Die Meldung, wenn die Grenze erreicht ist. Sie nennt den Ausweg, weil
     * eine Grenze ohne Ausweg nur aergert.
     */
    public function limitMessage(): string
    {
        if ($this->maxActiveListings === null) {
            return '';
        }

        return \sprintf(
            'Im Tarif "%s" sind %d aktive Anzeigen enthalten. Beende eine laufende Anzeige '
                . 'oder wechsle in einen größeren Tarif.',
            $this->plan->name,
            $this->maxActiveListings,
        );
    }
}
