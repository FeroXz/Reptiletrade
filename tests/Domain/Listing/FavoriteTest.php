<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Listing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Listing\Favorite;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Infra\Persistence\PdoFavoriteRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(PdoFavoriteRepository::class)]
#[CoversClass(Favorite::class)]
final class FavoriteTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private PdoFavoriteRepository $favorites;

    private int $userId;

    private int $listingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-09T12:00:00Z'));
        $this->favorites = new PdoFavoriteRepository($this->database);

        $this->userId = $this->createUser('interessent@example.tld');
        $this->listingId = $this->createListing($this->createUser('anbieter@example.tld'), $this->createSpecies());
    }

    public function testDoppeltesMerkenErgibtEinenEintrag(): void
    {
        self::assertTrue($this->favorites->add($this->userId, $this->listingId, $this->clock->now()));
        // Zweiter Klick: derselbe Wunsch, keine zweite Zeile — und kein Fehler.
        self::assertFalse($this->favorites->add($this->userId, $this->listingId, $this->clock->now()));

        self::assertSame(1, $this->favorites->countForUser($this->userId));
        self::assertSame(
            1,
            (int) (string) $this->database->scalar('SELECT COUNT(*) FROM listing_favorites'),
        );
    }

    public function testDerZeitpunktDesErstenMerkensBleibtStehen(): void
    {
        $this->favorites->add($this->userId, $this->listingId, $this->clock->now());

        $this->clock->travelTo($this->clock->now()->modify('+3 days'));
        $this->favorites->add($this->userId, $this->listingId, $this->clock->now());

        $eintrag = $this->favorites->forUser($this->userId)[0];

        self::assertSame('2026-08-09', $eintrag->markedAt->format('Y-m-d'));
    }

    public function testFremdeMerklistenSindNichtLesbar(): void
    {
        $fremder = $this->createUser('fremder@example.tld');

        $this->favorites->add($this->userId, $this->listingId, $this->clock->now());

        self::assertSame([], $this->favorites->forUser($fremder));
        self::assertSame(0, $this->favorites->countForUser($fremder));
        self::assertFalse($this->favorites->has($fremder, $this->listingId));
        // Und entfernen kann er sie auch nicht.
        self::assertFalse($this->favorites->remove($fremder, $this->listingId));
        self::assertTrue($this->favorites->has($this->userId, $this->listingId));
    }

    public function testDieLoeschungDerAnzeigeEntferntDenEintrag(): void
    {
        $this->favorites->add($this->userId, $this->listingId, $this->clock->now());

        $this->database->execute('DELETE FROM listings WHERE id = :id', ['id' => $this->listingId]);

        self::assertSame([], $this->favorites->forUser($this->userId));
        self::assertSame(
            0,
            (int) (string) $this->database->scalar('SELECT COUNT(*) FROM listing_favorites'),
        );
    }

    public function testDieLoeschungDesKontosEntferntDenEintrag(): void
    {
        $this->favorites->add($this->userId, $this->listingId, $this->clock->now());

        $this->database->execute('DELETE FROM users WHERE id = :id', ['id' => $this->userId]);

        self::assertSame(
            0,
            (int) (string) $this->database->scalar('SELECT COUNT(*) FROM listing_favorites'),
        );
    }

    public function testEinePausierteAnzeigeBleibtSichtbarUndGekennzeichnet(): void
    {
        $this->favorites->add($this->userId, $this->listingId, $this->clock->now());

        $this->database->execute(
            "UPDATE listings SET status = 'pausiert' WHERE id = :id",
            ['id' => $this->listingId],
        );

        $eintrag = $this->favorites->forUser($this->userId)[0];

        // Verschwinden waere schlimmer: Der Nutzer wuesste nicht, ob er sich
        // geirrt hat oder die Anwendung.
        self::assertSame(ListingStatus::Pausiert, $eintrag->status);
        self::assertFalse($eintrag->isReachable());
        self::assertSame('Testanzeige', $eintrag->title);
    }

    public function testEntmerkenEntferntNurDenEigenenEintrag(): void
    {
        $zweiter = $this->createUser('zweiter@example.tld');

        $this->favorites->add($this->userId, $this->listingId, $this->clock->now());
        $this->favorites->add($zweiter, $this->listingId, $this->clock->now());

        self::assertTrue($this->favorites->remove($this->userId, $this->listingId));

        self::assertFalse($this->favorites->has($this->userId, $this->listingId));
        self::assertTrue($this->favorites->has($zweiter, $this->listingId));
    }

    public function testDerZaehlerDesAnbietersZaehltAlleMerkungen(): void
    {
        $this->favorites->add($this->userId, $this->listingId, $this->clock->now());
        $this->favorites->add($this->createUser('zweiter@example.tld'), $this->listingId, $this->clock->now());

        self::assertSame(
            2,
            (int) (string) $this->database->scalar(
                'SELECT COUNT(*) FROM listing_favorites WHERE listing_id = :id',
                ['id' => $this->listingId],
            ),
        );
    }
}
