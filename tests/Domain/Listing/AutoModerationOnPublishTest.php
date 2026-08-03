<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Listing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Geo\Coordinates;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Geo\PostalCode;
use Reptilienmarkt\Domain\Listing\Handover;
use Reptilienmarkt\Domain\Listing\Listing;
use Reptilienmarkt\Domain\Listing\ListingMediaItem;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\ListingWizard;
use Reptilienmarkt\Domain\Listing\MorphStringGenerator;
use Reptilienmarkt\Domain\Setting\ArraySettings;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Domain\Trust\AutoModerationPolicy;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoLegalDocumentRepository;
use Reptilienmarkt\Infra\Persistence\PdoListingMediaRepository;
use Reptilienmarkt\Infra\Persistence\PdoListingRepository;
use Reptilienmarkt\Infra\Persistence\PdoPostalCodeRepository;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Infra\Persistence\PdoUserRepository;
use Reptilienmarkt\Legal\LegalGuard;
use Reptilienmarkt\Legal\LegalRuleFactory;
use Reptilienmarkt\Legal\LegalTextResolver;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Legal\InMemoryLegalTextRepository;
use Reptilienmarkt\Tests\Support\FrozenClock;

/**
 * Die Auto-Moderation neuer Konten (Phase 5) greift beim Veroeffentlichen —
 * zusaetzlich zur Rechtsentscheidung, nicht an ihrer Stelle.
 */
#[CoversClass(ListingWizard::class)]
#[CoversClass(AutoModerationPolicy::class)]
final class AutoModerationOnPublishTest extends DatabaseTestCase
{
    private const string JETZT = '2026-08-03T12:00:00+00:00';

    private PdoListingRepository $listings;

    private PdoListingMediaRepository $media;

    private PdoAuditLog $audit;

    private int $speciesId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listings = new PdoListingRepository($this->database);
        $this->media = new PdoListingMediaRepository($this->database);
        $this->audit = new PdoAuditLog($this->database);

        $this->speciesId = (new PdoSpeciesRepository($this->database))->save(new Species(
            null,
            'Pogona vitticeps',
            'Bartagame',
            slug: 'pogona-vitticeps',
        ));

        (new PdoPostalCodeRepository($this->database))->upsertMany([
            new PostalCode(Country::De, '80331', 'München', 'Bayern', new Coordinates(48.13743, 11.57549)),
        ]);
    }

    private function wizard(AutoModerationPolicy $policy): ListingWizard
    {
        $clock = new FrozenClock(new DateTimeImmutable(self::JETZT));

        $texts = new InMemoryLegalTextRepository();
        $texts->loadBundled(\dirname(__DIR__, 3) . '/data/legal_texts.json');

        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 3) . '/config/legal_rules.php';

        return new ListingWizard(
            $this->listings,
            $this->media,
            new PdoLegalDocumentRepository($this->database),
            new PdoSpeciesRepository($this->database),
            new PdoPostalCodeRepository($this->database),
            new PdoUserRepository($this->database),
            new LegalGuard(
                (new LegalRuleFactory($clock, new ArraySettings()))->fromConfig($config),
                new LegalTextResolver($texts),
            ),
            new MorphStringGenerator(),
            $this->audit,
            $clock,
            $policy,
        );
    }

    /**
     * @param non-empty-string $created
     */
    private function user(string $created = '2026-08-01T12:00:00+00:00'): User
    {
        $userId = $this->createUser('zuechter@example.tld');
        $moment = new DateTimeImmutable($created);

        $this->database->execute('UPDATE users SET created_at = :at WHERE id = :id', [
            'at' => $moment->format('Y-m-d\TH:i:s\Z'),
            'id' => $userId,
        ]);

        return (new PdoUserRepository($this->database))->findById($userId) ?? self::fail('Konto fehlt.');
    }

    private function readyListing(User $user): Listing
    {
        $id = $this->listings->create(new Listing(
            null,
            $user->id ?? 0,
            ListingType::Verkauf,
            $this->speciesId,
            'Bartagame Hypo, NZ 2026',
            'Kräftiges Tier aus eigener Nachzucht.',
            18000,
            'EUR',
            postalCode: '80331',
            country: Country::De,
            latitude: 48.13743,
            longitude: 11.57549,
            handover: Handover::Abholung,
        ));

        $this->media->add(new ListingMediaItem(0, $id, 'bild', 'anzeigen/' . $id . '/bild.webp', 0, true, 800, 600, 1000));

        return $this->listings->findById($id) ?? self::fail('Anzeige fehlt.');
    }

    public function testDieErsteAnzeigeEinesNeuenKontosGehtInDiePruefung(): void
    {
        $user = $this->user();
        $ergebnis = $this->wizard(new AutoModerationPolicy(true, 3, 30))->publish($this->readyListing($user), $user);

        self::assertTrue($ergebnis->published);
        self::assertSame(ListingStatus::Pruefung, $ergebnis->status);
        self::assertTrue($ergebnis->autoModerated);
        self::assertStringContainsString('neuen Kontos', $ergebnis->message());
    }

    public function testAbDerViertenAnzeigeGehtEsDirektOnline(): void
    {
        $user = $this->user();
        $wizard = $this->wizard(new AutoModerationPolicy(true, 3, 30));

        for ($i = 0; $i < 3; ++$i) {
            $wizard->publish($this->readyListing($user), $user);
        }

        $ergebnis = $wizard->publish($this->readyListing($user), $user);

        self::assertSame(ListingStatus::Aktiv, $ergebnis->status);
        self::assertFalse($ergebnis->autoModerated);
    }

    public function testEinAeltererAccountWirdNichtGebremst(): void
    {
        $user = $this->user('2026-01-01T12:00:00+00:00');
        $ergebnis = $this->wizard(new AutoModerationPolicy(true, 3, 30))->publish($this->readyListing($user), $user);

        self::assertSame(ListingStatus::Aktiv, $ergebnis->status);
    }

    public function testDerAuditTrailHaeltDieAutoModerationFest(): void
    {
        $user = $this->user();
        $listing = $this->readyListing($user);

        $this->wizard(new AutoModerationPolicy(true, 3, 30))->publish($listing, $user);

        $eintraege = $this->audit->forEntity('listing', $listing->id ?? 0);

        self::assertCount(1, $eintraege);
        self::assertSame('listing.published', $eintraege[0]['action']);
        self::assertTrue($eintraege[0]['data']['auto_moderation']);
        self::assertSame('pruefung', $eintraege[0]['data']['status']);
    }

    public function testAbgeschalteteAutoModerationAendertNichtsAmRecht(): void
    {
        $user = $this->user();
        $ergebnis = $this->wizard(new AutoModerationPolicy(false))->publish($this->readyListing($user), $user);

        self::assertSame(ListingStatus::Aktiv, $ergebnis->status);
        self::assertFalse($ergebnis->autoModerated);
    }
}
