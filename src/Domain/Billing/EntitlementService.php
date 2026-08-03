<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Support\Clock;

/**
 * Ermittelt die Rechte eines Kontos aus Tarif und laufendem Abo.
 *
 * Die eine Stelle, an der "Monetarisierung ist aus" ausgewertet wird. Alles
 * dahinter — Anzeigengrenze, Laufzeit, Bilderzahl, Merkmale — laeuft
 * unveraendert weiter, egal wie der Schalter steht.
 */
final readonly class EntitlementService
{
    /**
     * Grenzen, die auch ohne Abrechnung gelten sollen. Zwoelf Bilder je
     * Anzeige sind keine Verkaufsschranke, sondern eine Frage des
     * Speicherplatzes.
     */
    private const int TECHNICAL_IMAGE_LIMIT = 12;

    private const int DEFAULT_RUNTIME_DAYS = 60;

    public function __construct(
        private BillingConfiguration $config,
        private SubscriptionRepository $subscriptions,
        private UserRepository $users,
        private Clock $clock,
    ) {}

    public function forUser(User $user): Entitlements
    {
        $plans = $this->config->plans();
        $enabled = $this->config->enabled();

        $subscription = $this->activeSubscription($user);
        $planKey = $subscription === null ? $this->config->defaultPlanKey() : $subscription->planKey;

        // Ein Abo auf einen inzwischen entfernten Tarif faellt auf den
        // Grundtarif zurueck, statt die Seite mit einem Fehler abzubrechen.
        $plan = $plans[$planKey] ?? $plans[$this->config->defaultPlanKey()];

        if (!$enabled) {
            return new Entitlements(
                false,
                $plan,
                $subscription,
                // Ohne Abrechnung keine Anzeigengrenze — so laeuft die
                // Plattform heute, und daran aendert das Vorbereiten nichts.
                null,
                self::DEFAULT_RUNTIME_DAYS,
                self::TECHNICAL_IMAGE_LIMIT,
                Feature::cases(),
            );
        }

        return new Entitlements(
            true,
            $plan,
            $subscription,
            $plan->maxActiveListings(),
            $plan->runtimeDays(self::DEFAULT_RUNTIME_DAYS),
            $plan->maxImages(self::TECHNICAL_IMAGE_LIMIT),
            $plan->features,
        );
    }

    /**
     * Das laufende Abo — oder null. Ein Abo mit abgelaufener Periode zaehlt
     * nicht, auch wenn sein Status noch auf "aktiv" steht: Aufraeumen ist
     * Sache eines Jobs, Rechte vergeben ist es nicht.
     */
    public function activeSubscription(User $user): ?Subscription
    {
        $subscription = $this->subscriptions->activeForUser($user->id ?? 0);

        return $subscription?->isActiveAt($this->clock->now()) === true ? $subscription : null;
    }

    /**
     * Prueft vor dem Veroeffentlichen. Liefert null, wenn nichts im Weg steht.
     */
    public function publishBlocker(User $user): ?string
    {
        $entitlements = $this->forUser($user);

        if ($entitlements->unlimitedListings()) {
            return null;
        }

        $active = $this->users->salesStatistics($user->id ?? 0)['aktive_anzeigen'];

        return $entitlements->mayPublish($active) ? null : $entitlements->limitMessage();
    }

    public function enabled(): bool
    {
        return $this->config->enabled();
    }

    /**
     * @return array<string, Plan>
     */
    public function plans(): array
    {
        return $this->config->plans();
    }
}
