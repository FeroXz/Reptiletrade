<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Review;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Message\Conversation;
use Reptilienmarkt\Domain\Message\ConversationStatus;
use Reptilienmarkt\Domain\Review\Review;
use Reptilienmarkt\Domain\Review\ReviewException;
use Reptilienmarkt\Domain\Review\ReviewService;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoReviewRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(ReviewService::class)]
#[CoversClass(PdoReviewRepository::class)]
final class ReviewServiceTest extends DatabaseTestCase
{
    private ReviewService $reviews;

    private PdoReviewRepository $repository;

    private int $sellerId;

    private int $buyerId;

    private int $listingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sellerId = $this->createUser('zuechter@example.tld');
        $this->buyerId = $this->createUser('kaeufer@example.tld');
        $this->listingId = $this->createListing($this->sellerId, $this->createSpecies());

        $this->repository = new PdoReviewRepository($this->database);
        $this->reviews = new ReviewService(
            $this->repository,
            new PdoAuditLog($this->database),
            new FrozenClock(new DateTimeImmutable('2026-08-03T12:00:00+00:00')),
        );
    }

    private function conversation(bool $buyerConfirmed = true, bool $sellerConfirmed = true): Conversation
    {
        $moment = new DateTimeImmutable('2026-08-02T10:00:00+00:00');

        return new Conversation(
            1,
            $this->listingId,
            $this->buyerId,
            $this->sellerId,
            ConversationStatus::Offen,
            $buyerConfirmed ? $moment : null,
            $sellerConfirmed ? $moment->modify('+1 hour') : null,
        );
    }

    public function testBewertungNachBeidseitigBestaetigtemHandel(): void
    {
        $bewertung = $this->reviews->submit($this->conversation(), $this->buyerId, 5, 'Alles bestens.');

        self::assertSame(5, $bewertung->rating);
        self::assertSame($this->sellerId, $bewertung->toUserId);
        self::assertSame($this->buyerId, $bewertung->fromUserId);
    }

    /**
     * Die eigentliche Schutzmassnahme: Ohne beidseitige Bestaetigung liesse
     * sich ein Konto mit erfundenen Handeln aufwerten oder herabsetzen.
     */
    public function testOhneBestaetigungKeineBewertung(): void
    {
        $this->expectException(ReviewException::class);
        $this->expectExceptionMessageMatches('/beide Seiten/');

        $this->reviews->submit($this->conversation(sellerConfirmed: false), $this->buyerId, 5, null);
    }

    public function testEinseitigeBestaetigungReichtNicht(): void
    {
        $this->expectException(ReviewException::class);

        $this->reviews->submit($this->conversation(buyerConfirmed: false), $this->sellerId, 1, null);
    }

    public function testUnbeteiligteKoennenNichtBewerten(): void
    {
        $fremder = $this->createUser('fremd@example.tld');

        $this->expectException(ReviewException::class);

        $this->reviews->submit($this->conversation(), $fremder, 5, null);
    }

    public function testNurEineBewertungProHandel(): void
    {
        $this->reviews->submit($this->conversation(), $this->buyerId, 5, null);

        $this->expectException(ReviewException::class);
        $this->expectExceptionMessageMatches('/bereits bewertet/');

        $this->reviews->submit($this->conversation(), $this->buyerId, 1, null);
    }

    public function testBeideSeitenDuerfenEinanderBewerten(): void
    {
        $this->reviews->submit($this->conversation(), $this->buyerId, 5, null);
        $this->reviews->submit($this->conversation(), $this->sellerId, 4, null);

        self::assertSame(1, $this->reviews->summaryFor($this->sellerId)->count);
        self::assertSame(5, $this->reviews->recent($this->sellerId)[0]->rating);

        self::assertSame(1, $this->reviews->summaryFor($this->buyerId)->count);
        self::assertSame(4, $this->reviews->recent($this->buyerId)[0]->rating);
    }

    public function testBewertungAusserhalbDerSkalaWirdAbgelehnt(): void
    {
        $this->expectException(ReviewException::class);

        $this->reviews->submit($this->conversation(), $this->buyerId, 6, null);
    }

    public function testZuLangerKommentarWirdAbgelehnt(): void
    {
        $this->expectException(ReviewException::class);

        $this->reviews->submit($this->conversation(), $this->buyerId, 5, str_repeat('a', Review::MAX_COMMENT_LENGTH + 1));
    }

    public function testLeererKommentarWirdZuNull(): void
    {
        $bewertung = $this->reviews->submit($this->conversation(), $this->buyerId, 5, "   \n ");

        self::assertNull($bewertung->comment);
        self::assertFalse($bewertung->hasComment());
    }

    public function testSchnittUndVerteilung(): void
    {
        $this->reviews->submit($this->conversation(), $this->buyerId, 5, null);

        // Zweiter Handel, anderes Listing, anderer Kaeufer.
        $zweiterKaeufer = $this->createUser('zweiter@example.tld');
        $zweitesListing = $this->createListing($this->sellerId, $this->createSpecies('Pogona henrylawsoni', 'pogona-henrylawsoni'));
        $moment = new DateTimeImmutable('2026-08-02T10:00:00+00:00');

        $this->reviews->submit(
            new Conversation(2, $zweitesListing, $zweiterKaeufer, $this->sellerId, ConversationStatus::Offen, $moment, $moment),
            $zweiterKaeufer,
            3,
            null,
        );

        $zusammenfassung = $this->reviews->summaryFor($this->sellerId);

        self::assertSame(2, $zusammenfassung->count);
        self::assertSame(4.0, $zusammenfassung->rounded());
        self::assertSame(50.0, $zusammenfassung->share(5));
        self::assertSame(0.0, $zusammenfassung->share(1));
    }

    public function testOhneBewertungenGibtEsKeinenSchnitt(): void
    {
        $zusammenfassung = $this->reviews->summaryFor($this->sellerId);

        self::assertFalse($zusammenfassung->hasReviews());
        self::assertNull($zusammenfassung->rounded());
        self::assertSame(0.0, $zusammenfassung->share(5));
    }

    public function testMayReviewSpiegeltDieBedingungen(): void
    {
        self::assertTrue($this->reviews->mayReview($this->conversation(), $this->buyerId));
        self::assertFalse($this->reviews->mayReview($this->conversation(sellerConfirmed: false), $this->buyerId));

        $this->reviews->submit($this->conversation(), $this->buyerId, 5, null);

        self::assertFalse($this->reviews->mayReview($this->conversation(), $this->buyerId));
    }
}
