<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Search;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Billing\BillingConfiguration;
use Reptilienmarkt\Domain\Billing\BoostService;
use Reptilienmarkt\Domain\Search\ListingSummary;
use Reptilienmarkt\Domain\Search\SearchCriteria;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoBoostRepository;
use Reptilienmarkt\Infra\Persistence\PdoListingRepository;
use Reptilienmarkt\Infra\Search\ListingQuery;
use Reptilienmarkt\Infra\Search\PdoListingSearchRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

/**
 * Der Boost ist nur dann etwas wert, wenn die Trefferliste ihn auch beachtet.
 * Dieser Test verbindet beide Seiten: BoostService setzt die Hervorhebung,
 * die Suche sortiert danach.
 */
#[CoversClass(BoostService::class)]
#[CoversClass(PdoListingSearchRepository::class)]
final class BoostRankingTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private BoostService $boosts;

    private PdoListingSearchRepository $search;

    /** @var list<int> */
    private array $listingIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-03T12:00:00+00:00'));

        $userId = $this->createUser('zuechter@example.tld');
        $speciesId = $this->createSpecies();

        for ($i = 0; $i < 3; ++$i) {
            $this->listingIds[] = $this->createListing($userId, $speciesId, 'aktiv');
        }

        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 3) . '/config/monetarisierung.php';

        $this->boosts = new BoostService(
            new PdoBoostRepository($this->database),
            new PdoListingRepository($this->database),
            new BillingConfiguration($config),
            new PdoAuditLog($this->database),
            $this->clock,
        );

        $this->search = new PdoListingSearchRepository($this->database, new ListingQuery($this->clock));
    }

    /**
     * @return list<int>
     */
    private function resultIds(): array
    {
        return array_map(
            static fn(ListingSummary $treffer): int => $treffer->id,
            $this->search->search(new SearchCriteria())->listings,
        );
    }

    public function testOhneBoostGiltDieNormaleReihenfolge(): void
    {
        // Neueste zuerst: die zuletzt angelegte Anzeige steht oben.
        self::assertSame(array_reverse($this->listingIds), $this->resultIds());
    }

    public function testEineGeboosteteAnzeigeStehtOben(): void
    {
        // Die aelteste Anzeige — ohne Boost stuende sie ganz unten.
        $unterste = $this->listingIds[0];

        $this->boosts->activate($unterste, 'top_7');

        self::assertSame($unterste, $this->resultIds()[0]);
    }

    public function testNachAblaufFaelltSieZurueck(): void
    {
        $unterste = $this->listingIds[0];
        $this->boosts->activate($unterste, 'top_7');

        self::assertSame($unterste, $this->resultIds()[0]);

        $this->clock->travelTo(new DateTimeImmutable('2026-08-11T12:00:00+00:00'));
        $this->boosts->expireDue();

        self::assertSame(array_reverse($this->listingIds), $this->resultIds());
    }
}
