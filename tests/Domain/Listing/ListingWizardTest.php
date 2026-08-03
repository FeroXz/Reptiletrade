<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Listing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Geo\Coordinates;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Geo\PostalCode;
use Reptilienmarkt\Domain\Listing\LegalDocType;
use Reptilienmarkt\Domain\Listing\LegalDocumentRecord;
use Reptilienmarkt\Domain\Listing\Listing;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\ListingWizard;
use Reptilienmarkt\Domain\Listing\MorphStringGenerator;
use Reptilienmarkt\Domain\Listing\Zygosity;
use Reptilienmarkt\Domain\Setting\ArraySettings;
use Reptilienmarkt\Domain\Species\BnatschgStatus;
use Reptilienmarkt\Domain\Species\EuAnnex;
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
 * Zusammenspiel von Assistent und Rechts-Engine beim Veroeffentlichen.
 */
#[CoversClass(ListingWizard::class)]
#[CoversClass(PdoListingRepository::class)]
final class ListingWizardTest extends DatabaseTestCase
{
    private const string NOW = '2026-08-02T12:00:00+00:00';

    private ListingWizard $wizard;

    private PdoListingRepository $listings;

    private PdoLegalDocumentRepository $legalDocuments;

    private PdoListingMediaRepository $media;

    private AuditLog $audit;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $clock = new FrozenClock(new DateTimeImmutable(self::NOW));

        $this->listings = new PdoListingRepository($this->database);
        $this->legalDocuments = new PdoLegalDocumentRepository($this->database);
        $this->media = new PdoListingMediaRepository($this->database);
        $this->audit = new PdoAuditLog($this->database);

        $texts = new InMemoryLegalTextRepository();
        $texts->loadBundled(\dirname(__DIR__, 3) . '/data/legal_texts.json');

        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 3) . '/config/legal_rules.php';
        $rules = (new LegalRuleFactory($clock, new ArraySettings()))->fromConfig($config);

        $postalCodes = new PdoPostalCodeRepository($this->database);
        $postalCodes->upsertMany([
            new PostalCode(Country::De, '80331', 'München', 'Bayern', new Coordinates(48.13743, 11.57549)),
        ]);

        $this->wizard = new ListingWizard(
            $this->listings,
            $this->media,
            $this->legalDocuments,
            new PdoSpeciesRepository($this->database),
            $postalCodes,
            new PdoUserRepository($this->database),
            new LegalGuard($rules, new LegalTextResolver($texts)),
            new MorphStringGenerator(),
            $this->audit,
            $clock,
            // Die Auto-Moderation neuer Konten (Phase 5) ist hier abgeschaltet:
            // Diese Tests pruefen, was die Rechts-Engine mit dem Status macht,
            // und das liesse sich sonst nicht von der Kontoprüfung trennen.
            new AutoModerationPolicy(false),
        );

        $userId = $this->createUser('zuechter@example.tld');
        $this->user = new User($userId, 'zuechter@example.tld', 'Testzüchter');
    }

    private function speciesId(bool $geschuetzt = false): int
    {
        $repository = new PdoSpeciesRepository($this->database);

        return $repository->save($geschuetzt
            ? new Species(
                null,
                'Testudo hermanni',
                'Griechische Landschildkröte',
                'testudo-hermanni',
                null,
                null,
                null,
                EuAnnex::A,
                BnatschgStatus::Streng,
                true,
                true,
                false,
                null,
                null,
                null,
                12,
                20,
            )
            : new Species(
                null,
                'Pogona vitticeps',
                'Bartagame',
                'pogona-vitticeps',
                null,
                null,
                null,
                null,
                BnatschgStatus::NichtGeschuetzt,
                false,
                false,
                false,
                null,
                null,
                null,
                8,
                25,
            ));
    }

    private function draft(int $speciesId, bool $vollstaendig = true): Listing
    {
        $id = $this->listings->create(new Listing(
            null,
            $this->user->id ?? 0,
            ListingType::Verkauf,
            $speciesId,
            $vollstaendig ? 'Bartagame Nachzucht 2026' : '',
            'Kerngesundes Tier aus eigener Nachzucht.',
            25000,
            'EUR',
            false,
            null,
            \Reptilienmarkt\Domain\Listing\Sex::Weiblich,
            new DateTimeImmutable('2026-01-01'),
            120,
            1,
            \Reptilienmarkt\Domain\Listing\CbStatus::Nachzucht,
            ListingStatus::Entwurf,
            $vollstaendig ? '80331' : null,
            $vollstaendig ? Country::De : null,
        ));

        if ($vollstaendig) {
            $this->database->execute(
                "INSERT INTO listing_media (listing_id, media_type, path, is_primary, created_at)
                 VALUES (:id, 'bild', 'test/1.webp', 1, :now)",
                ['id' => $id, 'now' => gmdate('Y-m-d\TH:i:s\Z')],
            );
        }

        $listing = $this->listings->findById($id);
        self::assertNotNull($listing);

        return $listing;
    }

    public function testUnkritischeAnzeigeGehtDirektOnline(): void
    {
        $listing = $this->draft($this->speciesId());

        $ergebnis = $this->wizard->publish($listing, $this->user);

        self::assertTrue($ergebnis->published);
        self::assertSame(ListingStatus::Aktiv, $ergebnis->status);
        self::assertFalse($ergebnis->needsReview());

        $gespeichert = $this->listings->findById($listing->id ?? 0);
        self::assertNotNull($gespeichert);
        self::assertSame(ListingStatus::Aktiv, $gespeichert->status);
        self::assertNotNull($gespeichert->expiresAt, 'Die Laufzeit muss gesetzt sein.');
    }

    public function testAnhangAOhneBescheinigungWirdBlockiert(): void
    {
        $listing = $this->draft($this->speciesId(geschuetzt: true));

        $ergebnis = $this->wizard->publish($listing, $this->user);

        self::assertFalse($ergebnis->published);
        self::assertTrue($ergebnis->decision->blocked);

        $gespeichert = $this->listings->findById($listing->id ?? 0);
        self::assertNotNull($gespeichert);
        self::assertSame(ListingStatus::Entwurf, $gespeichert->status, 'Ein blockierter Entwurf bleibt Entwurf.');
    }

    public function testAnhangAMitAllenAngabenLandetInDerPruefung(): void
    {
        $speciesId = $this->speciesId(geschuetzt: true);
        $listing = $this->draft($speciesId);

        $this->legalDocuments->save(new LegalDocumentRecord(
            null,
            $listing->id ?? 0,
            LegalDocType::EuBescheinigung,
            'DE-BW-2026-000123',
            'Regierungspräsidium Karlsruhe',
            null,
            'listing-1/2026/08/abc.pdf',
        ));

        $this->listings->save(new Listing(
            $listing->id,
            $listing->userId,
            $listing->type,
            $listing->speciesId,
            $listing->title,
            $listing->description,
            $listing->priceCents,
            $listing->currency,
            $listing->negotiable,
            $listing->tradeWanted,
            $listing->sex,
            $listing->hatchDate,
            $listing->weightG,
            $listing->countAvailable,
            $listing->cbStatus,
            $listing->status,
            $listing->postalCode,
            $listing->country,
            $listing->latitude,
            $listing->longitude,
            $listing->handover,
            [
                'meldung_bestaetigt' => '1',
                'meldung_datum' => '2026-07-01',
                'kennzeichnung_art' => 'transponder',
                'kennzeichnung_nummer' => '276098106543210',
            ],
        ));

        $aktualisiert = $this->listings->findById($listing->id ?? 0);
        self::assertNotNull($aktualisiert);

        $ergebnis = $this->wizard->publish($aktualisiert, $this->user);

        self::assertTrue($ergebnis->published, implode(' | ', $ergebnis->errors));
        self::assertSame(ListingStatus::Pruefung, $ergebnis->status);
        self::assertTrue($ergebnis->needsReview());
    }

    public function testUnvollstaendigeAnzeigeWirdNichtVeroeffentlicht(): void
    {
        $listing = $this->draft($this->speciesId(), vollstaendig: false);

        $ergebnis = $this->wizard->publish($listing, $this->user);

        self::assertFalse($ergebnis->published);
        self::assertNotSame([], $ergebnis->errors);
    }

    public function testFehlendesBildVerhindertDieVeroeffentlichung(): void
    {
        $listing = $this->draft($this->speciesId());
        $this->database->execute('DELETE FROM listing_media WHERE listing_id = :id', ['id' => $listing->id]);

        $fehler = $this->wizard->completenessErrors($listing);

        self::assertContains('Mindestens ein Bild ist nötig.', $fehler);
    }

    /**
     * Jede Entscheidung landet im Audit-Trail — auch die ablehnende.
     */
    public function testJedeEntscheidungWirdProtokolliert(): void
    {
        $listing = $this->draft($this->speciesId(geschuetzt: true));
        $this->wizard->publish($listing, $this->user);

        $eintraege = $this->audit->forEntity('listing', $listing->id ?? 0);

        self::assertCount(1, $eintraege);
        self::assertSame('listing.publish_blocked', $eintraege[0]['action']);
        self::assertTrue($eintraege[0]['data']['blocked']);
        self::assertArrayHasKey('reasons', $eintraege[0]['data']);
    }

    public function testVeroeffentlichungWirdProtokolliert(): void
    {
        $listing = $this->draft($this->speciesId());
        $this->wizard->publish($listing, $this->user);

        $eintraege = $this->audit->forEntity('listing', $listing->id ?? 0);

        self::assertCount(1, $eintraege);
        self::assertSame('listing.published', $eintraege[0]['action']);
        self::assertSame('aktiv', $eintraege[0]['data']['status']);
    }

    public function testMorphStringEntstehtAusDerAuswahl(): void
    {
        $speciesId = $this->speciesId();
        $listing = $this->draft($speciesId);

        $hypo = $this->createMorphFor($speciesId, 'Hypomelanistic', ['Hypo']);
        $trans = $this->createMorphFor($speciesId, 'Translucent', ['Trans']);
        $zero = $this->createMorphFor($speciesId, 'Zero');

        $this->listings->replaceMorphs($listing->id ?? 0, [
            $hypo => Zygosity::Visual,
            $trans => Zygosity::Visual,
            $zero => Zygosity::Het,
        ]);

        self::assertSame('Hypo Trans het Zero', $this->wizard->morphString($listing->id ?? 0));
        self::assertSame('hypo/hypo trans/trans zero/+', $this->wizard->genotype($listing->id ?? 0));
    }

    /**
     * @param list<string> $aliases
     */
    private function createMorphFor(int $speciesId, string $name, array $aliases = []): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->database->execute(
            "INSERT INTO morphs (species_id, name, aliases, inheritance, created_at, updated_at)
             VALUES (:species_id, :name, :aliases, 'recessive', :now, :now)",
            [
                'species_id' => $speciesId,
                'name' => $name,
                'aliases' => json_encode($aliases, \JSON_THROW_ON_ERROR),
                'now' => $now,
            ],
        );

        return $this->database->lastInsertId();
    }
}
