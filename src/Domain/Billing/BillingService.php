<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Audit\AuditActorType;
use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Support\Clock;

/**
 * Kauf und Abrechnung.
 *
 * Vollstaendig verdrahtet, aber ohne aktiven Zahlungsanbieter wirkungslos:
 * jeder Kaufvorgang endet dann in einer klaren Meldung statt in einer halb
 * angelegten Bestellung.
 *
 * Der Ablauf ist bewusst zweistufig — erst wird ein offener Beleg angelegt,
 * dann fuehrt die Rueckmeldung des Anbieters ihn auf "bezahlt". Nur die
 * Rueckmeldung schaltet Leistungen frei, nie die Rueckkehr des Browsers von
 * der Bezahlseite: Die laesst sich aufrufen, ohne bezahlt zu haben.
 */
final readonly class BillingService
{
    public function __construct(
        private BillingConfiguration $config,
        private SubscriptionRepository $subscriptions,
        private PaymentRepository $payments,
        private BoostService $boosts,
        private PaymentProvider $provider,
        private AuditLog $audit,
        private Clock $clock,
        private string $appUrl = 'https://example.tld',
    ) {}

    public function enabled(): bool
    {
        return $this->config->enabled();
    }

    /**
     * Kann ueberhaupt gekauft werden? Nur dann zeigt die Oberflaeche
     * Kaufknoepfe.
     */
    public function purchasable(): bool
    {
        return $this->config->enabled() && $this->provider->isOperational();
    }

    public function providerName(): string
    {
        return $this->provider->name();
    }

    // ------------------------------------------------------------------ Abo

    /**
     * Eroeffnet den Kauf einer Mitgliedschaft.
     *
     * @throws BillingException
     */
    public function startSubscriptionCheckout(User $user, string $planKey): CheckoutSession
    {
        $this->guardPurchasable();

        $plans = $this->config->plans();
        $plan = $plans[$planKey] ?? throw new BillingException('Diesen Tarif gibt es nicht.');

        if ($plan->isFree()) {
            throw new BillingException('Der kostenlose Tarif muss nicht gekauft werden.');
        }

        $laufend = $this->subscriptions->activeForUser($user->id ?? 0);
        if ($laufend !== null && $laufend->isActiveAt($this->clock->now()) && $laufend->planKey === $planKey) {
            throw new BillingException('Dieser Tarif läuft bereits.');
        }

        $paymentId = $this->payments->save(new Payment(
            null,
            $user->id ?? 0,
            PaymentPurpose::Abo,
            $plan->price,
            PaymentStatus::Offen,
            'subscription',
            null,
            $this->taxRateFor($user),
            $this->provider->name(),
            null,
            null,
            $this->clock->now(),
        ));

        $session = $this->provider->createCheckout(
            new CheckoutRequest($user, PaymentPurpose::Abo, $planKey, $plan->name, $plan->price, $plan->interval),
            $this->appUrl . '/konto/zahlung/erfolg',
            $this->appUrl . '/konto/zahlung/abbruch',
        );

        $this->payments->save(new Payment(
            $paymentId,
            $user->id ?? 0,
            PaymentPurpose::Abo,
            $plan->price,
            PaymentStatus::Offen,
            'subscription',
            null,
            $this->taxRateFor($user),
            $session->provider,
            $session->providerReference,
            null,
            $this->clock->now(),
        ));

        $this->audit->record(new AuditEntry(
            'billing.checkout_started',
            'user',
            $user->id,
            ['zweck' => PaymentPurpose::Abo->value, 'tarif' => $planKey, 'payment_id' => $paymentId],
            $user->id,
        ));

        return $session;
    }

    /**
     * Kuendigt zum Periodenende. Sofortiges Abschalten waere falsch — bezahlt
     * ist bezahlt.
     *
     * @throws BillingException
     */
    public function cancelSubscription(User $user): Subscription
    {
        $subscription = $this->subscriptions->activeForUser($user->id ?? 0)
            ?? throw new BillingException('Es läuft keine Mitgliedschaft.');

        if ($subscription->providerReference !== null) {
            $this->provider->cancelSubscription($subscription->providerReference);
        }

        $this->subscriptions->markCancelAtPeriodEnd($subscription->id ?? 0, $this->clock->now());

        $this->audit->record(new AuditEntry(
            'billing.subscription_cancelled',
            'user',
            $user->id,
            ['tarif' => $subscription->planKey, 'laeuft_bis' => $subscription->currentPeriodEnd->format('c')],
            $user->id,
        ));

        return $this->subscriptions->findById($subscription->id ?? 0) ?? $subscription;
    }

    // ---------------------------------------------------------------- Boost

    /**
     * @throws BillingException
     */
    public function startBoostCheckout(User $user, int $listingId, string $boostKey): CheckoutSession
    {
        $this->guardPurchasable();

        $option = $this->boosts->option($boostKey) ?? throw new BillingException('Diese Top-Platzierung gibt es nicht.');

        $paymentId = $this->payments->save(new Payment(
            null,
            $user->id ?? 0,
            PaymentPurpose::Boost,
            $option->price,
            PaymentStatus::Offen,
            'listing',
            $listingId,
            $this->taxRateFor($user),
            $this->provider->name(),
            null,
            null,
            $this->clock->now(),
        ));

        $session = $this->provider->createCheckout(
            new CheckoutRequest($user, PaymentPurpose::Boost, $boostKey, $option->name, $option->price, null, $listingId),
            $this->appUrl . '/konto/zahlung/erfolg',
            $this->appUrl . '/konto/zahlung/abbruch',
        );

        $this->payments->save(new Payment(
            $paymentId,
            $user->id ?? 0,
            PaymentPurpose::Boost,
            $option->price,
            PaymentStatus::Offen,
            'listing',
            $listingId,
            $this->taxRateFor($user),
            $session->provider,
            $session->providerReference,
            null,
            $this->clock->now(),
        ));

        return $session;
    }

    // -------------------------------------------------------- Rueckmeldung

    /**
     * Nimmt eine Rueckmeldung des Anbieters entgegen und schaltet frei.
     *
     * Mehrfachzustellung ist der Normalfall, nicht die Ausnahme: Geht die
     * Antwort auf dem Weg verloren, schickt der Anbieter dieselbe Nachricht
     * erneut. Deshalb ist der Vorgang idempotent — ein bereits verbuchter
     * Beleg fuehrt zu keiner zweiten Gutschrift.
     *
     * @param array<string, string> $headers
     *
     * @return bool ob die Nachricht verarbeitet wurde
     */
    public function handleWebhook(string $payload, array $headers): bool
    {
        $event = $this->provider->parseWebhook($payload, $headers);

        if ($event === null) {
            return false;
        }

        $payment = $this->payments->findByProviderReference($this->provider->name(), $event->providerReference);

        if ($payment === null || $payment->id === null) {
            return false;
        }

        if ($payment->status->isSettled()) {
            // Schon verbucht — nichts zu tun, und das ist kein Fehler.
            return true;
        }

        if ($event->status !== PaymentStatus::Bezahlt) {
            $this->payments->updateStatus($payment->id, $event->status);

            return true;
        }

        $now = $this->clock->now();
        $this->payments->markPaid($payment->id, $now);

        match ($payment->purpose) {
            PaymentPurpose::Abo => $this->grantSubscription($payment, $event, $now),
            PaymentPurpose::Boost => $this->grantBoost($payment),
        };

        $this->audit->record(new AuditEntry(
            'billing.payment_settled',
            'user',
            $payment->userId,
            ['zweck' => $payment->purpose->value, 'payment_id' => $payment->id],
            null,
            AuditActorType::System,
        ));

        return true;
    }

    /**
     * Setzt abgelaufene Abos auf "abgelaufen". Ruft im Betrieb ein Job auf.
     *
     * @return int Anzahl der betroffenen Abos
     */
    public function expireDueSubscriptions(int $limit = 100): int
    {
        $now = $this->clock->now();
        $anzahl = 0;

        foreach ($this->subscriptions->expiredBefore($now, $limit) as $subscription) {
            $this->subscriptions->updateStatus($subscription->id ?? 0, SubscriptionStatus::Abgelaufen, $now);
            ++$anzahl;
        }

        return $anzahl;
    }

    /**
     * @return list<Payment>
     */
    public function paymentsFor(User $user): array
    {
        return $this->payments->forUser($user->id ?? 0);
    }

    private function grantSubscription(Payment $payment, PaymentEvent $event, DateTimeImmutable $now): void
    {
        $plans = $this->config->plans();
        $laufend = $this->subscriptions->activeForUser($payment->userId);

        // Aus dem Beleg allein geht der Tarif nicht hervor; er steht im
        // laufenden Vorgang oder faellt auf den ersten kostenpflichtigen Tarif
        // zurueck.
        $planKey = $laufend?->planKey;

        if ($planKey === null) {
            foreach ($plans as $key => $plan) {
                if (!$plan->isFree() && $plan->price->cents === $payment->amount->cents) {
                    $planKey = $key;

                    break;
                }
            }
        }

        $plan = $plans[$planKey ?? ''] ?? null;
        if ($plan === null) {
            return;
        }

        $step = $plan->interval->step() ?? '+1 month';
        $end = $now->modify($step);

        if ($laufend !== null && $laufend->id !== null) {
            $this->subscriptions->extendPeriod($laufend->id, $now, $end, $now);
            $this->subscriptions->updateStatus($laufend->id, SubscriptionStatus::Aktiv, $now);

            return;
        }

        $this->subscriptions->save(new Subscription(
            null,
            $payment->userId,
            $plan->key,
            SubscriptionStatus::Aktiv,
            $now,
            $end,
            false,
            $this->provider->name(),
            $event->externalSubscriptionId,
            $now,
        ));
    }

    private function grantBoost(Payment $payment): void
    {
        if ($payment->referenceId === null) {
            return;
        }

        // Der Boost-Schluessel steckt nicht im Beleg — er ergibt sich aus dem
        // bezahlten Betrag, und mehrdeutige Preise faengt die Konfiguration ab.
        foreach ($this->boosts->options() as $key => $option) {
            if ($option->price->cents === $payment->amount->cents) {
                $this->boosts->activate($payment->referenceId, $key, $payment->id, null);

                return;
            }
        }
    }

    private function taxRateFor(User $user): float
    {
        return $this->config->taxRateFor($user->country === null ? 'DE' : $user->country->value);
    }

    /**
     * @throws BillingException
     */
    private function guardPurchasable(): void
    {
        if (!$this->config->enabled()) {
            throw new BillingException('Die Monetarisierung ist vorbereitet, aber nicht aktiviert.');
        }

        if (!$this->provider->isOperational()) {
            throw new BillingException('Es ist kein einsatzbereiter Zahlungsanbieter eingerichtet.');
        }
    }
}
