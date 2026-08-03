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
use Reptilienmarkt\Domain\Setting\ArraySettings;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Infra\Payment\NullPaymentProvider;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoBoostRepository;
use Reptilienmarkt\Infra\Persistence\PdoListingRepository;
use Reptilienmarkt\Infra\Persistence\PdoPaymentRepository;
use Reptilienmarkt\Infra\Persistence\PdoSubscriptionRepository;
use Reptilienmarkt\Infra\Persistence\PdoUserRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

/**
 * Der Kern von Phase 6: vorbereitet, aber nicht aktiviert.
 *
 * Diese Tests halten fest, dass der abgeschaltete Zustand der Normalfall ist —
 * keine Anzeigengrenze, keine kostenpflichtigen Merkmale, kein Kauf.
 */
#[CoversClass(BillingConfiguration::class)]
#[CoversClass(EntitlementService::class)]
#[CoversClass(BillingService::class)]
#[CoversClass(NullPaymentProvider::class)]
final class BillingSwitchTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-03T12:00:00+00:00'));
        $this->userId = $this->createUser('zuechter@example.tld');
    }

    /**
     * Die echte Konfiguration, wahlweise mit umgelegtem Schalter.
     *
     * @return array<string, mixed>
     */
    private function config(bool $enabled = false): array
    {
        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 3) . '/config/monetarisierung.php';
        $config['enabled'] = $enabled;

        return $config;
    }

    private function entitlements(bool $enabled = false): EntitlementService
    {
        return new EntitlementService(
            new BillingConfiguration($this->config($enabled)),
            new PdoSubscriptionRepository($this->database),
            new PdoUserRepository($this->database),
            $this->clock,
        );
    }

    private function billing(bool $enabled = false): BillingService
    {
        $config = new BillingConfiguration($this->config($enabled));

        return new BillingService(
            $config,
            new PdoSubscriptionRepository($this->database),
            new PdoPaymentRepository($this->database),
            new BoostService(
                new PdoBoostRepository($this->database),
                new PdoListingRepository($this->database),
                $config,
                new PdoAuditLog($this->database),
                $this->clock,
            ),
            new NullPaymentProvider(),
            new PdoAuditLog($this->database),
            $this->clock,
        );
    }

    private function user(): User
    {
        return new User($this->userId, 'zuechter@example.tld', 'Testzüchter', Role::Seller);
    }

    // ---------------------------------------------------- Ausgelieferter Stand

    /**
     * Der wichtigste Test dieser Phase: So, wie die Datei im Repository liegt,
     * ist die Monetarisierung aus.
     */
    public function testDieAusgelieferteKonfigurationIstAbgeschaltet(): void
    {
        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 3) . '/config/monetarisierung.php';

        self::assertFalse((new BillingConfiguration($config))->enabled());
        self::assertSame('keiner', (new BillingConfiguration($config))->providerName());
    }

    // -------------------------------------------------------- Abgeschaltet

    public function testOhneAbrechnungGibtEsKeineAnzeigengrenze(): void
    {
        $rechte = $this->entitlements(false)->forUser($this->user());

        self::assertFalse($rechte->billingEnabled);
        self::assertTrue($rechte->unlimitedListings());
        self::assertTrue($rechte->mayPublish(999));
        self::assertNull($rechte->remainingListings(999));
    }

    public function testOhneAbrechnungGibtEsAlleMerkmale(): void
    {
        $rechte = $this->entitlements(false)->forUser($this->user());

        foreach (Feature::cases() as $merkmal) {
            self::assertTrue($rechte->has($merkmal), $merkmal->value);
        }
    }

    public function testOhneAbrechnungBlockiertNichtsDasVeroeffentlichen(): void
    {
        self::assertNull($this->entitlements(false)->publishBlocker($this->user()));
    }

    public function testOhneAbrechnungLaeuftEineAnzeige60Tage(): void
    {
        self::assertSame(60, $this->entitlements(false)->forUser($this->user())->runtimeDays);
    }

    public function testOhneAbrechnungLaesstSichNichtsKaufen(): void
    {
        $billing = $this->billing(false);

        self::assertFalse($billing->enabled());
        self::assertFalse($billing->purchasable());

        $this->expectException(BillingException::class);
        $this->expectExceptionMessageMatches('/nicht aktiviert/');

        $billing->startSubscriptionCheckout($this->user(), 'zuechter');
    }

    public function testDerNullAnbieterVerweigertOffen(): void
    {
        $provider = new NullPaymentProvider();

        self::assertFalse($provider->isOperational());
        self::assertNull($provider->parseWebhook('{}', []));
        self::assertFalse($provider->cancelSubscription('sub_123'));
    }

    // --------------------------------------------------------- Angeschaltet

    public function testMitAbrechnungGiltDieGrenzeDesGrundtarifs(): void
    {
        $rechte = $this->entitlements(true)->forUser($this->user());

        self::assertTrue($rechte->billingEnabled);
        self::assertSame(3, $rechte->maxActiveListings);
        self::assertTrue($rechte->mayPublish(2));
        self::assertFalse($rechte->mayPublish(3));
        self::assertSame(1, $rechte->remainingListings(2));
    }

    public function testMitAbrechnungFehlenDemGrundtarifDieZuechtermerkmale(): void
    {
        $rechte = $this->entitlements(true)->forUser($this->user());

        self::assertFalse($rechte->has(Feature::NachzuchtAnkuendigung));
        self::assertFalse($rechte->has(Feature::Statistiken));
    }

    public function testDieGrenzeGreiftErstAbDerViertenAnzeige(): void
    {
        $speciesId = $this->createSpecies();
        $service = $this->entitlements(true);

        for ($i = 0; $i < 3; ++$i) {
            $frei = $service->publishBlocker($this->user());
            self::assertNull($frei, 'Anzeige ' . ($i + 1));
            $this->createListing($this->userId, $speciesId, 'aktiv');
        }

        $blocker = $service->publishBlocker($this->user());

        self::assertNotNull($blocker);
        self::assertStringContainsString('3 aktive Anzeigen', $blocker);
    }

    /**
     * Entwuerfe und beendete Anzeigen zaehlen nicht mit — sonst waere die
     * Grenze eine Falle.
     */
    public function testNurAktiveAnzeigenZaehlenGegenDieGrenze(): void
    {
        $speciesId = $this->createSpecies();

        foreach (['entwurf', 'verkauft', 'abgelaufen', 'gesperrt'] as $status) {
            $this->createListing($this->userId, $speciesId, $status);
        }

        self::assertNull($this->entitlements(true)->publishBlocker($this->user()));
    }

    public function testEinSettingStichtDieKonfigurationsdatei(): void
    {
        $settings = new ArraySettings(['billing.enabled' => true]);
        $config = new BillingConfiguration($this->config(false), $settings);

        // Der Betreiber soll ohne Deployment umlegen koennen — in beide
        // Richtungen.
        self::assertTrue($config->enabled());
        self::assertFalse((new BillingConfiguration($this->config(false)))->enabled());
    }
}
