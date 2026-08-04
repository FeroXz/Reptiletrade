<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Breeding;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Billing\BillingConfiguration;
use Reptilienmarkt\Domain\Billing\EntitlementService;
use Reptilienmarkt\Domain\Breeding\AnnouncementException;
use Reptilienmarkt\Domain\Breeding\AnnouncementStatus;
use Reptilienmarkt\Domain\Breeding\BreedingAnnouncementService;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Infra\Persistence\PdoBreedingAnnouncementRepository;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Infra\Persistence\PdoSubscriptionRepository;
use Reptilienmarkt\Infra\Persistence\PdoUserRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(BreedingAnnouncementService::class)]
#[CoversClass(PdoBreedingAnnouncementRepository::class)]
final class BreedingAnnouncementServiceTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private PdoBreedingAnnouncementRepository $repository;

    private int $userId;

    private int $speciesId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-03T12:00:00+00:00'));
        $this->userId = $this->createUser('zuechter@example.tld');
        $this->speciesId = $this->createSpecies();
        $this->repository = new PdoBreedingAnnouncementRepository($this->database);
    }

    private function service(bool $billingEnabled = false, bool $planWithFeature = true): BreedingAnnouncementService
    {
        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 3) . '/config/monetarisierung.php';
        $config['enabled'] = $billingEnabled;

        if (!$planWithFeature) {
            $config['plans']['frei']['features'] = [];
        }

        return new BreedingAnnouncementService(
            $this->repository,
            new PdoSpeciesRepository($this->database),
            new EntitlementService(
                new BillingConfiguration($config),
                new PdoSubscriptionRepository($this->database),
                new PdoUserRepository($this->database),
                $this->clock,
            ),
            $this->clock,
        );
    }

    private function user(): User
    {
        return new User($this->userId, 'zuechter@example.tld', 'Testzüchter', Role::Breeder);
    }

    public function testOhneAbrechnungDarfJederAnkuendigen(): void
    {
        self::assertTrue($this->service(false)->mayAnnounce($this->user()));
    }

    public function testInDerStartphaseDarfAuchMitAbrechnungJederAnkuendigen(): void
    {
        // Der Grundtarif fuehrt das Merkmal (config/monetarisierung.php).
        self::assertTrue($this->service(true)->mayAnnounce($this->user()));
    }

    public function testOhneMerkmalImTarifBleibtEsVerschlossen(): void
    {
        self::assertFalse($this->service(true, false)->mayAnnounce($this->user()));
    }

    public function testAnkuendigungEntstehtAlsEntwurf(): void
    {
        $ankuendigung = $this->service()->create(
            $this->user(),
            $this->speciesId,
            'Hypo x het Zero, Schlupf im Juni',
            'Verpaarung steht, Eier liegen im Inkubator.',
            '2026-09-15',
            'Hypo Trans x het Zero',
        );

        self::assertSame(AnnouncementStatus::Entwurf, $ankuendigung->status);
        self::assertFalse($ankuendigung->isPublic());
        self::assertSame('Hypo Trans x het Zero', $ankuendigung->morphNote);
    }

    public function testOhneTarifWirdAbgelehnt(): void
    {
        $this->expectException(AnnouncementException::class);
        $this->expectExceptionMessageMatches('/Züchter-Tarif/');

        $this->service(true, false)->create($this->user(), $this->speciesId, 'Hypo x het Zero');
    }

    public function testUnbekannteArtWirdAbgelehnt(): void
    {
        $this->expectException(AnnouncementException::class);

        $this->service()->create($this->user(), 9999, 'Hypo x het Zero');
    }

    public function testZuKurzerTitelWirdAbgelehnt(): void
    {
        $this->expectException(AnnouncementException::class);

        $this->service()->create($this->user(), $this->speciesId, 'Hypo');
    }

    /**
     * Eine Ankuendigung fuer die Vergangenheit ist keine Ankuendigung.
     */
    public function testTerminInDerVergangenheitWirdAbgelehnt(): void
    {
        $this->expectException(AnnouncementException::class);
        $this->expectExceptionMessageMatches('/Vergangenheit/');

        $this->service()->create($this->user(), $this->speciesId, 'Hypo x het Zero', null, '2026-01-01');
    }

    public function testVeroeffentlichenMachtSieSichtbar(): void
    {
        $service = $this->service();
        $ankuendigung = $service->create($this->user(), $this->speciesId, 'Hypo x het Zero');

        self::assertSame([], $service->publicForSpecies($this->speciesId));

        $service->changeStatus($ankuendigung, $this->user(), AnnouncementStatus::Veroeffentlicht);

        self::assertCount(1, $service->publicForSpecies($this->speciesId));
    }

    public function testFremdeAnkuendigungenLassenSichNichtAendern(): void
    {
        $service = $this->service();
        $ankuendigung = $service->create($this->user(), $this->speciesId, 'Hypo x het Zero');

        $fremder = new User($this->createUser('fremd@example.tld'), 'fremd@example.tld', 'Fremd');

        $this->expectException(AnnouncementException::class);

        $service->changeStatus($ankuendigung, $fremder, AnnouncementStatus::Veroeffentlicht);
    }

    public function testUeberfaelligeAnkuendigungenLassenSichFinden(): void
    {
        $service = $this->service();
        $ankuendigung = $service->create($this->user(), $this->speciesId, 'Hypo x het Zero', null, '2026-08-20');
        $service->changeStatus($ankuendigung, $this->user(), AnnouncementStatus::Veroeffentlicht);

        self::assertSame([], $service->overdue());

        // Nach dem erwarteten Termin gehoert sie aufgeraeumt: Eine seit Monaten
        // "erwartete" Nachzucht ist ein Vertrauensschaden.
        $this->clock->travelTo(new DateTimeImmutable('2026-09-01T12:00:00+00:00'));

        self::assertCount(1, $service->overdue());
    }

    public function testEntwuerfeGeltenNichtAlsUeberfaellig(): void
    {
        $service = $this->service();
        $service->create($this->user(), $this->speciesId, 'Hypo x het Zero', null, '2026-08-20');

        $this->clock->travelTo(new DateTimeImmutable('2026-09-01T12:00:00+00:00'));

        self::assertSame([], $service->overdue());
    }
}
