<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\User;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Domain\Listing\PauseActor;
use Reptilienmarkt\Domain\Privacy\AccountDeletionService;
use Reptilienmarkt\Domain\User\BanState;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Domain\User\UserModerationException;
use Reptilienmarkt\Domain\User\UserModerationService;
use Reptilienmarkt\Domain\User\UserStatus;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoListingRepository;
use Reptilienmarkt\Infra\Persistence\PdoSessionRepository;
use Reptilienmarkt\Infra\Persistence\PdoUserRepository;
use Reptilienmarkt\Infra\Search\Fts5SearchIndex;
use Reptilienmarkt\Infra\Search\ListingIndexer;
use Reptilienmarkt\Infra\Storage\PrivateStorage;
use Reptilienmarkt\Infra\Storage\PublicImageStorage;
use Reptilienmarkt\Support\Timestamp;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(UserModerationService::class)]
#[CoversClass(BanState::class)]
final class UserModerationServiceTest extends DatabaseTestCase
{
    private UserModerationService $moderation;

    private PdoUserRepository $users;

    private PdoListingRepository $listings;

    private FrozenClock $clock;

    private int $targetId;

    private int $adminId;

    private int $listingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->users = new PdoUserRepository($this->database);
        $this->listings = new PdoListingRepository($this->database);

        $this->targetId = $this->createUser('nutzer@example.tld');
        $this->adminId = $this->createUser('admin@example.tld');
        $this->database->execute("UPDATE users SET role = 'admin' WHERE id = :id", ['id' => $this->adminId]);
        $this->listingId = $this->createListing($this->targetId, $this->createSpecies(), 'aktiv');

        $verzeichnis = sys_get_temp_dir() . '/reptilienmarkt-mod-' . bin2hex(random_bytes(6));
        mkdir($verzeichnis, 0o775, true);

        $this->moderation = new UserModerationService(
            $this->users,
            new PdoSessionRepository($this->database),
            $this->listings,
            new ListingIndexer($this->database, new Fts5SearchIndex($this->database)),
            new AccountDeletionService(
                $this->database,
                new PublicImageStorage($verzeichnis),
                new PrivateStorage($verzeichnis),
                new PdoAuditLog($this->database),
                $this->clock,
            ),
            new PdoAuditLog($this->database),
            $this->clock,
        );
    }

    public function testEineSperreSchliesstDenZugangUndNimmtDieAnzeigenVomMarkt(): void
    {
        $this->sitzungAnlegen();

        $this->moderation->ban($this->target(), $this->admin(), 'Verstoß gegen die Regeln');

        self::assertSame(UserStatus::Gesperrt, $this->users->findById($this->targetId)?->status);
        // Der Status allein reicht nicht: Wer angemeldet ist, bliebe es sonst.
        self::assertSame(0, $this->anzahl('SELECT COUNT(*) FROM sessions WHERE user_id = ' . $this->targetId));
        self::assertSame(ListingStatus::Pausiert, $this->listings->findById($this->listingId)?->status);
    }

    public function testEineBefristeteSperreNenntIhrEnde(): void
    {
        $bis = $this->clock->now()->modify('+7 days');

        $this->moderation->ban($this->target(), $this->admin(), 'Zu klären', $bis);
        $zustand = $this->moderation->banState($this->target());

        self::assertNotNull($zustand);
        self::assertFalse($zustand->isPermanent());
        self::assertStringContainsString('Zu klären', $zustand->explanation());
        self::assertFalse($zustand->hasExpired($this->clock->now()));
        self::assertTrue($zustand->hasExpired($bis->modify('+1 second')));
    }

    public function testOhneGrundKeineSperre(): void
    {
        $this->expectException(UserModerationException::class);
        $this->expectExceptionMessageMatches('/Grund/');

        $this->moderation->ban($this->target(), $this->admin(), '   ');
    }

    public function testEinEndeInDerVergangenheitWirdAbgelehnt(): void
    {
        $this->expectException(UserModerationException::class);

        $this->moderation->ban($this->target(), $this->admin(), 'Grund', $this->clock->now()->modify('-1 hour'));
    }

    public function testDasEigeneKontoLaesstSichNichtSperren(): void
    {
        $this->expectException(UserModerationException::class);
        $this->expectExceptionMessageMatches('/eigene Konto/');

        $this->moderation->ban($this->admin(), $this->admin(), 'Grund');
    }

    public function testEinVerwaltungskontoIstGeschuetzt(): void
    {
        $zweiter = $this->createUser('admin2@example.tld');
        $this->database->execute("UPDATE users SET role = 'admin' WHERE id = :id", ['id' => $zweiter]);

        $this->expectException(UserModerationException::class);
        $this->expectExceptionMessageMatches('/Rolle/');

        $this->moderation->ban(
            new User($zweiter, 'admin2@example.tld', 'Admin 2', Role::Admin),
            $this->admin(),
            'Grund',
        );
    }

    public function testOhneAdminrolleGehtGarNichts(): void
    {
        $this->expectException(UserModerationException::class);
        $this->expectExceptionMessageMatches('/Berechtigung/');

        $this->moderation->ban($this->target(), $this->target(), 'Grund');
    }

    public function testEntsperrenLaesstDieAnzeigenWiederAnlaufen(): void
    {
        $this->moderation->ban($this->target(), $this->admin(), 'Kurz zu klären', $this->clock->now()->modify('+2 days'));
        $this->moderation->unban($this->users->findById($this->targetId) ?? $this->target(), $this->admin());

        self::assertSame(UserStatus::Aktiv, $this->users->findById($this->targetId)?->status);
        self::assertNull($this->moderation->banState($this->target()));
        self::assertSame(ListingStatus::Aktiv, $this->listings->findById($this->listingId)?->status);
    }

    public function testEineEigenePauseUeberlebtDasEntsperren(): void
    {
        // Der Anbieter hat selbst pausiert — das Entsperren des Kontos soll
        // seine Entscheidung nicht umwerfen.
        $zweite = $this->createListing($this->targetId, $this->createSpecies('Boa constrictor', 'boa'), 'aktiv');
        $this->listings->pause($zweite, PauseActor::Anbieter, null, ListingStatus::Aktiv);

        $this->moderation->ban($this->target(), $this->admin(), 'Grund');
        $this->moderation->unban($this->users->findById($this->targetId) ?? $this->target(), $this->admin());

        self::assertSame(ListingStatus::Pausiert, $this->listings->findById($zweite)?->status);
        self::assertSame(ListingStatus::Aktiv, $this->listings->findById($this->listingId)?->status);
    }

    public function testAbgelaufeneSperrenHebtDerJobAuf(): void
    {
        $this->moderation->ban($this->target(), $this->admin(), 'Grund', $this->clock->now()->modify('+1 day'));

        self::assertSame(0, $this->moderation->releaseExpired());

        $this->clock->travelTo($this->clock->now()->modify('+2 days'));

        self::assertSame(1, $this->moderation->releaseExpired());
        self::assertSame(UserStatus::Aktiv, $this->users->findById($this->targetId)?->status);
    }

    public function testEineUnbefristeteSperreLaeuftNichtVonSelbstAus(): void
    {
        $this->moderation->ban($this->target(), $this->admin(), 'Dauerhaft');
        $this->clock->travelTo($this->clock->now()->modify('+10 years'));

        self::assertSame(0, $this->moderation->releaseExpired());
    }

    public function testLoeschenNutztDieselbeAbwaegungWieDieSelbstloeschung(): void
    {
        $kaeufer = $this->createUser('kaeufer@example.tld');
        $this->database->execute(
            'INSERT INTO reviews (listing_id, from_user_id, to_user_id, rating, deal_confirmed_at, created_at)
             VALUES (:listing, :from, :to, 5, :now, :now)',
            ['listing' => $this->listingId, 'from' => $kaeufer, 'to' => $this->targetId, 'now' => Timestamp::now()],
        );

        $ergebnis = $this->moderation->delete($this->target(), $this->admin());

        // Bewertungen gehoeren auch der Gegenseite — also anonymisieren.
        self::assertTrue($ergebnis->anonymized);
        self::assertSame(1, $this->anzahl('SELECT COUNT(*) FROM reviews WHERE to_user_id = ' . $this->targetId));
    }

    private function target(): User
    {
        return new User($this->targetId, 'nutzer@example.tld', 'Testnutzer');
    }

    private function admin(): User
    {
        return new User($this->adminId, 'admin@example.tld', 'Verwaltung', Role::Admin);
    }

    private function sitzungAnlegen(): void
    {
        $this->database->execute(
            'INSERT INTO sessions (id, user_id, payload, created_at, last_seen_at, expires_at)
             VALUES (:id, :user, :payload, :now, :now, :bis)',
            [
                'id' => bin2hex(random_bytes(16)),
                'user' => $this->targetId,
                'payload' => '{}',
                'now' => Timestamp::now(),
                'bis' => Timestamp::utc($this->clock->now()->modify('+1 day')),
            ],
        );
    }

    private function anzahl(string $sql): int
    {
        $value = $this->database->scalar($sql);

        return (int) (is_numeric($value) ? $value : 0);
    }
}
