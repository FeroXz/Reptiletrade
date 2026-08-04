<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Listing;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Listing\SellerStats;
use Reptilienmarkt\Domain\Listing\SellerStatsService;
use Reptilienmarkt\Infra\Persistence\PdoListingRepository;
use Reptilienmarkt\Support\Timestamp;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(SellerStatsService::class)]
#[CoversClass(SellerStats::class)]
final class SellerStatsServiceTest extends DatabaseTestCase
{
    private SellerStatsService $stats;

    private PdoListingRepository $listings;

    private FrozenClock $clock;

    private int $sellerId;

    private int $listingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->stats = new SellerStatsService($this->database, $this->clock);
        $this->listings = new PdoListingRepository($this->database);
        $this->sellerId = $this->createUser('anbieter@example.tld');
        $this->listingId = $this->createListing($this->sellerId, $this->createSpecies(), 'aktiv');
    }

    public function testAufrufeUndAnfragenWerdenGezaehlt(): void
    {
        $this->listings->recordView($this->listingId);
        $this->listings->recordView($this->listingId);
        $this->anfrage();

        $ergebnis = $this->stats->forUser($this->sellerId);

        self::assertSame(2, $ergebnis->views);
        self::assertSame(1, $ergebnis->enquiries);
        self::assertSame(50.0, $ergebnis->enquiryRate());
        self::assertTrue($ergebnis->hasData());
    }

    public function testOhneAufrufeGibtEsKeineQuote(): void
    {
        $ergebnis = $this->stats->forUser($this->sellerId);

        // Ohne Nenner keine Division — und keine irrefuehrende Null.
        self::assertNull($ergebnis->enquiryRate());
        self::assertFalse($ergebnis->hasData());
    }

    public function testDieZeitreiheHatKeineLuecken(): void
    {
        $this->listings->recordView($this->listingId);

        $ergebnis = $this->stats->forUser($this->sellerId, 30);

        // Ein Diagramm, das fehlende Tage weglaesst, staucht die Zeitachse und
        // zeigt einen Verlauf, den es nicht gab.
        self::assertCount(30, $ergebnis->viewsPerDay);
        self::assertCount(30, $ergebnis->enquiriesPerDay);
        self::assertSame(1, $ergebnis->viewsPerDay[gmdate('Y-m-d')]);
    }

    public function testAelteresFaelltAusDemZeitraum(): void
    {
        $this->database->execute(
            'INSERT INTO listing_views (listing_id, day, views) VALUES (:id, :tag, 9)',
            ['id' => $this->listingId, 'tag' => $this->clock->now()->modify('-60 days')->format('Y-m-d')],
        );

        self::assertSame(0, $this->stats->forUser($this->sellerId, 30)->views);
        self::assertSame(9, $this->stats->forUser($this->sellerId, 90)->views);
    }

    public function testFremdeAnzeigenZaehlenNichtMit(): void
    {
        $fremd = $this->createListing($this->createUser('fremd@example.tld'), $this->createSpecies('Boa constrictor', 'boa'), 'aktiv');
        $this->listings->recordView($fremd);

        self::assertSame(0, $this->stats->forUser($this->sellerId)->views);
    }

    public function testEntwuerfeStehenNichtInDerListe(): void
    {
        $this->createListing($this->sellerId, $this->createSpecies('Boa constrictor', 'boa'), 'entwurf');

        // Ein Entwurf war nie zu sehen — eine Zeile mit null Aufrufen waere
        // nur Rauschen.
        $ergebnis = $this->stats->forUser($this->sellerId);

        self::assertCount(1, $ergebnis->listings);
        self::assertSame($this->listingId, $ergebnis->listings[0]->id);
    }

    public function testDerZeitraumWirdBegrenzt(): void
    {
        self::assertSame(7, $this->stats->forUser($this->sellerId, 1)->days);
        self::assertSame(365, $this->stats->forUser($this->sellerId, 9999)->days);
    }

    private function anfrage(): void
    {
        $this->database->execute(
            'INSERT INTO conversations (listing_id, buyer_id, seller_id, message_count, created_at)
             VALUES (:listing, :buyer, :seller, 1, :now)',
            [
                'listing' => $this->listingId,
                'buyer' => $this->createUser('kaeufer@example.tld'),
                'seller' => $this->sellerId,
                'now' => Timestamp::now(),
            ],
        );
    }
}
