<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Billing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Billing\BillingConfiguration;
use Reptilienmarkt\Domain\Billing\BillingException;
use Reptilienmarkt\Domain\Billing\BillingService;
use Reptilienmarkt\Domain\Billing\BoostService;
use Reptilienmarkt\Domain\Billing\EntitlementService;
use Reptilienmarkt\Domain\Billing\Feature;
use Reptilienmarkt\Domain\Billing\PaymentStatus;
use Reptilienmarkt\Domain\Billing\SubscriptionStatus;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoBoostRepository;
use Reptilienmarkt\Infra\Persistence\PdoListingRepository;
use Reptilienmarkt\Infra\Persistence\PdoPaymentRepository;
use Reptilienmarkt\Infra\Persistence\PdoSubscriptionRepository;
use Reptilienmarkt\Infra\Persistence\PdoUserRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FakePaymentProvider;
use Reptilienmarkt\Tests\Support\FrozenClock;

/**
 * Der vollstaendige Kaufweg mit einem erfundenen Anbieter — Kauf, Rueckmeldung,
 * Freischaltung, Kuendigung, Ablauf.
 */
#[CoversClass(BillingService::class)]
#[CoversClass(BoostService::class)]
#[CoversClass(PdoSubscriptionRepository::class)]
#[CoversClass(PdoPaymentRepository::class)]
#[CoversClass(PdoBoostRepository::class)]
final class BillingFlowTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private FakePaymentProvider $provider;

    private BillingService $billing;

    private BoostService $boosts;

    private EntitlementService $entitlements;

    private PdoPaymentRepository $payments;

    private PdoSubscriptionRepository $subscriptions;

    private PdoListingRepository $listings;

    private int $userId;

    private int $listingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-03T12:00:00+00:00'));
        $this->provider = new FakePaymentProvider();

        $this->userId = $this->createUser('zuechter@example.tld');
        $this->listingId = $this->createListing($this->userId, $this->createSpecies());

        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 3) . '/config/monetarisierung.php';
        $config['enabled'] = true;
        $billingConfig = new BillingConfiguration($config);

        $this->payments = new PdoPaymentRepository($this->database);
        $this->subscriptions = new PdoSubscriptionRepository($this->database);
        $this->listings = new PdoListingRepository($this->database);

        $this->boosts = new BoostService(
            new PdoBoostRepository($this->database),
            $this->listings,
            $billingConfig,
            new PdoAuditLog($this->database),
            $this->clock,
        );

        $this->billing = new BillingService(
            $billingConfig,
            $this->subscriptions,
            $this->payments,
            $this->boosts,
            $this->provider,
            new PdoAuditLog($this->database),
            $this->clock,
            'https://markt.example',
        );

        $this->entitlements = new EntitlementService(
            $billingConfig,
            $this->subscriptions,
            new PdoUserRepository($this->database),
            $this->clock,
        );
    }

    private function user(): User
    {
        return new User($this->userId, 'zuechter@example.tld', 'Testzüchter', Role::Seller);
    }

    // ------------------------------------------------------------------ Abo

    public function testKaufLegtEinenOffenenBelegAn(): void
    {
        $session = $this->billing->startSubscriptionCheckout($this->user(), 'zuechter');

        self::assertStringStartsWith('https://', $session->redirectUrl);

        $beleg = $this->payments->findByProviderReference('fake', $session->providerReference);

        self::assertNotNull($beleg);
        self::assertSame(PaymentStatus::Offen, $beleg->status);
        self::assertSame(990, $beleg->amount->cents);
    }

    /**
     * Die Rueckkehr von der Bezahlseite schaltet nichts frei — nur die
     * Rueckmeldung des Anbieters tut das.
     */
    public function testErstDieRueckmeldungSchaltetFrei(): void
    {
        $session = $this->billing->startSubscriptionCheckout($this->user(), 'zuechter');

        $vorher = $this->entitlements->activeSubscription($this->user());
        self::assertNull($vorher, 'Vor der Rueckmeldung darf nichts freigeschaltet sein.');

        $this->billing->handleWebhook(...$this->provider->paidEvent($session->providerReference));

        $abo = $this->entitlements->activeSubscription($this->user());

        self::assertNotNull($abo);
        self::assertSame('zuechter', $abo->planKey);
        self::assertSame(SubscriptionStatus::Aktiv, $abo->status);
    }

    public function testDasAboSchaltetDieMerkmaleFrei(): void
    {
        $session = $this->billing->startSubscriptionCheckout($this->user(), 'zuechter');
        $this->billing->handleWebhook(...$this->provider->paidEvent($session->providerReference));

        $rechte = $this->entitlements->forUser($this->user());

        self::assertTrue($rechte->unlimitedListings());
        self::assertTrue($rechte->has(Feature::NachzuchtAnkuendigung));
        self::assertSame(90, $rechte->runtimeDays);
    }

    /**
     * Ein Webhook wird wiederholt zugestellt, wenn die Antwort verlorengeht.
     * Zweimal buchen darf er trotzdem nicht.
     */
    public function testDieselbeRueckmeldungZweimalAendertNichts(): void
    {
        $session = $this->billing->startSubscriptionCheckout($this->user(), 'zuechter');
        $event = $this->provider->paidEvent($session->providerReference);

        self::assertTrue($this->billing->handleWebhook(...$event));
        self::assertTrue($this->billing->handleWebhook(...$event));

        self::assertSame(1, (int) $this->database->scalar('SELECT COUNT(*) FROM subscriptions'));
        self::assertSame(
            1,
            (int) $this->database->scalar("SELECT COUNT(*) FROM payments WHERE status = 'bezahlt'"),
        );
    }

    public function testEineUnbekannteRueckmeldungWirdVerworfen(): void
    {
        self::assertFalse($this->billing->handleWebhook(...$this->provider->paidEvent('gibt-es-nicht')));
    }

    public function testFehlgeschlageneZahlungSchaltetNichtsFrei(): void
    {
        $session = $this->billing->startSubscriptionCheckout($this->user(), 'zuechter');
        $this->billing->handleWebhook(...$this->provider->failedEvent($session->providerReference));

        self::assertNull($this->entitlements->activeSubscription($this->user()));

        $beleg = $this->payments->findByProviderReference('fake', $session->providerReference);
        self::assertSame(PaymentStatus::Fehlgeschlagen, $beleg?->status);
    }

    public function testDerKostenloseTarifLaesstSichNichtKaufen(): void
    {
        $this->expectException(BillingException::class);

        $this->billing->startSubscriptionCheckout($this->user(), 'frei');
    }

    public function testEinUnbekannterTarifWirdAbgelehnt(): void
    {
        $this->expectException(BillingException::class);

        $this->billing->startSubscriptionCheckout($this->user(), 'platin');
    }

    public function testDerLaufendeTarifLaesstSichNichtErneutKaufen(): void
    {
        $session = $this->billing->startSubscriptionCheckout($this->user(), 'zuechter');
        $this->billing->handleWebhook(...$this->provider->paidEvent($session->providerReference));

        $this->expectException(BillingException::class);
        $this->expectExceptionMessageMatches('/läuft bereits/');

        $this->billing->startSubscriptionCheckout($this->user(), 'zuechter');
    }

    // ------------------------------------------------------------ Kuendigung

    public function testKuendigungGiltZumPeriodenende(): void
    {
        $session = $this->billing->startSubscriptionCheckout($this->user(), 'zuechter');
        $this->billing->handleWebhook(...$this->provider->paidEvent($session->providerReference));

        $abo = $this->billing->cancelSubscription($this->user());

        self::assertSame(SubscriptionStatus::Gekuendigt, $abo->status);
        self::assertTrue($abo->cancelAtPeriodEnd);

        // Bezahlt ist bezahlt: Die Rechte bleiben bis zum Periodenende.
        self::assertTrue($this->entitlements->forUser($this->user())->has(Feature::Statistiken));
        self::assertTrue($this->entitlements->forUser($this->user())->unlimitedListings());
    }

    public function testNachAblaufFallenDieRechteZurueck(): void
    {
        $session = $this->billing->startSubscriptionCheckout($this->user(), 'zuechter');
        $this->billing->handleWebhook(...$this->provider->paidEvent($session->providerReference));

        $this->clock->travelTo(new DateTimeImmutable('2026-10-03T12:00:00+00:00'));

        self::assertNull($this->entitlements->activeSubscription($this->user()));
        self::assertFalse($this->entitlements->forUser($this->user())->has(Feature::Statistiken));
        self::assertSame(3, $this->entitlements->forUser($this->user())->maxActiveListings);
    }

    public function testAufraeumenSetztAbgelaufeneAbos(): void
    {
        $session = $this->billing->startSubscriptionCheckout($this->user(), 'zuechter');
        $this->billing->handleWebhook(...$this->provider->paidEvent($session->providerReference));

        $this->clock->travelTo(new DateTimeImmutable('2026-10-03T12:00:00+00:00'));

        self::assertSame(1, $this->billing->expireDueSubscriptions());

        // Nach dem Aufraeumen taucht es in der Abfrage nach laufenden Abos
        // nicht mehr auf — deshalb ueber die Kennung nachsehen.
        $id = (int) $this->database->scalar('SELECT id FROM subscriptions LIMIT 1');
        self::assertSame(SubscriptionStatus::Abgelaufen, $this->subscriptions->findById($id)?->status);
    }

    public function testOhneAboLaesstSichNichtsKuendigen(): void
    {
        $this->expectException(BillingException::class);

        $this->billing->cancelSubscription($this->user());
    }

    // ---------------------------------------------------------------- Boost

    public function testBoostHebtDieAnzeigeHervor(): void
    {
        $session = $this->billing->startBoostCheckout($this->user(), $this->listingId, 'top_7');
        $this->billing->handleWebhook(...$this->provider->paidEvent($session->providerReference, 490));

        self::assertTrue($this->listings->isFeatured($this->listingId));

        $boost = $this->boosts->activeFor($this->listingId);
        self::assertNotNull($boost);
        self::assertSame(7, $boost->daysLeft($this->clock->now()));
    }

    public function testEinZweiterBoostVerlaengertStattZuErsetzen(): void
    {
        $this->boosts->activate($this->listingId, 'top_7');
        $zweiter = $this->boosts->activate($this->listingId, 'top_7');

        // Der zweite beginnt, wo der erste endet — bezahlte Zeit verfaellt nicht.
        self::assertEquals(
            $this->clock->now()->modify('+7 days'),
            $zweiter->startsAt,
        );
        self::assertEquals(
            $this->clock->now()->modify('+14 days'),
            $zweiter->endsAt,
        );
    }

    public function testAbgelaufeneBoostsWerdenAbgeraeumt(): void
    {
        $this->boosts->activate($this->listingId, 'top_7');
        self::assertTrue($this->listings->isFeatured($this->listingId));

        $this->clock->travelTo(new DateTimeImmutable('2026-08-11T12:00:00+00:00'));

        self::assertSame(1, $this->boosts->expireDue());
        self::assertFalse($this->listings->isFeatured($this->listingId));
    }

    public function testEinNachfolgerHaeltDieHervorhebung(): void
    {
        $this->boosts->activate($this->listingId, 'top_7');
        $this->boosts->activate($this->listingId, 'top_7');

        $this->clock->travelTo(new DateTimeImmutable('2026-08-11T12:00:00+00:00'));

        // Der erste ist abgelaufen, der zweite laeuft noch.
        self::assertSame(0, $this->boosts->expireDue());
        self::assertTrue($this->listings->isFeatured($this->listingId));
    }

    public function testDasAufraeumenLaeuftMehrfachOhneSchaden(): void
    {
        $this->boosts->activate($this->listingId, 'top_7');
        $this->clock->travelTo(new DateTimeImmutable('2026-08-11T12:00:00+00:00'));

        self::assertSame(1, $this->boosts->expireDue());
        self::assertSame(0, $this->boosts->expireDue());
    }

    public function testEinUnbekannterBoostWirdAbgelehnt(): void
    {
        $this->expectException(BillingException::class);

        $this->boosts->activate($this->listingId, 'top_999');
    }
}
