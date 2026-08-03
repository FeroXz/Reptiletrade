<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Moderation;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Moderation\ReportException;
use Reptilienmarkt\Domain\Moderation\ReportReason;
use Reptilienmarkt\Domain\Moderation\ReportService;
use Reptilienmarkt\Domain\Moderation\ReportStatus;
use Reptilienmarkt\Domain\Moderation\ReportTargetType;
use Reptilienmarkt\Domain\Trust\RateLimit;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Domain\Trust\RateLimitExceededException;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoRateLimitRepository;
use Reptilienmarkt\Infra\Persistence\PdoReportRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(ReportService::class)]
#[CoversClass(PdoReportRepository::class)]
final class ReportServiceTest extends DatabaseTestCase
{
    private ReportService $reports;

    private PdoReportRepository $repository;

    private FrozenClock $clock;

    private int $reporterId;

    private int $listingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-03T12:00:00+00:00'));
        $this->reporterId = $this->createUser('melder@example.tld');
        $this->listingId = $this->createListing($this->createUser('zuechter@example.tld'), $this->createSpecies());

        $this->repository = new PdoReportRepository($this->database);
        $this->reports = new ReportService(
            $this->repository,
            $this->limiter(),
            new PdoAuditLog($this->database),
            $this->clock,
        );
    }

    private function limiter(int $limit = 10): RateLimiter
    {
        return new RateLimiter(
            new PdoRateLimitRepository($this->database),
            $this->clock,
            [
                'meldung.konto' => new RateLimit('meldung.konto', $limit, 3600),
                'meldung.ip' => new RateLimit('meldung.ip', $limit * 2, 3600),
            ],
        );
    }

    private function reporter(): User
    {
        return new User($this->reporterId, 'melder@example.tld', 'Melder', Role::Buyer);
    }

    private function moderator(): User
    {
        return new User($this->createUser('mod@example.tld'), 'mod@example.tld', 'Moderation', Role::Moderator);
    }

    public function testMeldungLandetInDerListe(): void
    {
        $meldung = $this->reports->report(
            $this->reporter(),
            ReportTargetType::Listing,
            $this->listingId,
            ReportReason::Betrug,
            'Verlangt Vorkasse.',
            '203.0.113.7',
        );

        self::assertSame(ReportStatus::Offen, $meldung->status);
        self::assertSame(1, $this->reports->openCount());
        self::assertCount(1, $this->reports->queue());
    }

    /**
     * Wer zweimal klickt, hat nichts falsch gemacht — die Moderation braucht
     * den Eintrag aber nicht doppelt.
     */
    public function testDoppelteMeldungErzeugtKeinenZweitenEintrag(): void
    {
        $erste = $this->reports->report($this->reporter(), ReportTargetType::Listing, $this->listingId, ReportReason::Spam);
        $zweite = $this->reports->report($this->reporter(), ReportTargetType::Listing, $this->listingId, ReportReason::Betrug);

        self::assertSame($erste->id, $zweite->id);
        self::assertSame(1, $this->reports->openCount());
    }

    public function testVerschiedeneMelderZaehlenGetrennt(): void
    {
        $zweiter = new User($this->createUser('zweiter@example.tld'), 'zweiter@example.tld', 'Zweiter');

        $this->reports->report($this->reporter(), ReportTargetType::Listing, $this->listingId, ReportReason::Spam);
        $this->reports->report($zweiter, ReportTargetType::Listing, $this->listingId, ReportReason::Spam);

        self::assertSame(2, $this->reports->openCount());
    }

    public function testNiemandMeldetSichSelbst(): void
    {
        $this->expectException(ReportException::class);

        $this->reports->report($this->reporter(), ReportTargetType::User, $this->reporterId, ReportReason::Spam);
    }

    public function testRateLimitBremstMeldewellen(): void
    {
        $enge = new ReportService($this->repository, $this->limiter(2), new PdoAuditLog($this->database), $this->clock);

        $enge->report($this->reporter(), ReportTargetType::Listing, $this->listingId, ReportReason::Spam);
        $enge->report($this->reporter(), ReportTargetType::User, $this->createUser('a@example.tld'), ReportReason::Spam);

        $this->expectException(RateLimitExceededException::class);

        $enge->report($this->reporter(), ReportTargetType::User, $this->createUser('b@example.tld'), ReportReason::Spam);
    }

    /**
     * Tierschutz und Betrug stehen oben — dort ist der Schaden am groessten,
     * wenn niemand hinsieht.
     */
    public function testDringendeGruendeStehenObenInDerListe(): void
    {
        $melder = [];
        foreach (['spam', 'tierschutz', 'sonstiges', 'betrug'] as $index => $grund) {
            $melder[$grund] = new User($this->createUser('melder' . $index . '@example.tld'), 'm' . $index . '@example.tld', 'M');
        }

        $this->reports->report($melder['spam'], ReportTargetType::Listing, $this->listingId, ReportReason::Spam);
        $this->reports->report($melder['sonstiges'], ReportTargetType::Listing, $this->listingId, ReportReason::Sonstiges);
        $this->reports->report($melder['tierschutz'], ReportTargetType::Listing, $this->listingId, ReportReason::Tierschutz);
        $this->reports->report($melder['betrug'], ReportTargetType::Listing, $this->listingId, ReportReason::Betrug);

        $reihenfolge = array_map(
            static fn(object $meldung): string => $meldung->reason->value,
            $this->reports->queue(),
        );

        self::assertSame(['tierschutz', 'betrug', 'spam', 'sonstiges'], $reihenfolge);
    }

    public function testBearbeitenNimmtDieMeldungAusDerListe(): void
    {
        $meldung = $this->reports->report($this->reporter(), ReportTargetType::Listing, $this->listingId, ReportReason::Spam);

        $this->reports->resolve($meldung, $this->moderator(), ReportStatus::Erledigt, 'Anzeige gesperrt.');

        self::assertSame(0, $this->reports->openCount());
        self::assertSame(ReportStatus::Erledigt, ($this->repository->findById($meldung->id ?? 0))?->status);
    }

    public function testInPruefungBleibtInDerListe(): void
    {
        $meldung = $this->reports->report($this->reporter(), ReportTargetType::Listing, $this->listingId, ReportReason::Spam);

        $this->reports->resolve($meldung, $this->moderator(), ReportStatus::InPruefung, null);

        self::assertSame(1, $this->reports->openCount());
    }

    public function testNurDieModerationDarfBearbeiten(): void
    {
        $meldung = $this->reports->report($this->reporter(), ReportTargetType::Listing, $this->listingId, ReportReason::Spam);

        $this->expectException(ReportException::class);

        $this->reports->resolve($meldung, $this->reporter(), ReportStatus::Erledigt, null);
    }

    public function testEineMeldungLaesstSichNichtAufOffenZuruecksetzen(): void
    {
        $meldung = $this->reports->report($this->reporter(), ReportTargetType::Listing, $this->listingId, ReportReason::Spam);

        $this->expectException(ReportException::class);

        $this->reports->resolve($meldung, $this->moderator(), ReportStatus::Offen, null);
    }

    public function testZuLangeBeschreibungWirdGekuerztStattAbgelehnt(): void
    {
        $meldung = $this->reports->report(
            $this->reporter(),
            ReportTargetType::Listing,
            $this->listingId,
            ReportReason::Sonstiges,
            str_repeat('a', 5000),
        );

        self::assertSame(2000, mb_strlen((string) $meldung->description));
    }
}
