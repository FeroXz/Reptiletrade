<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Support\Demo;

use Reptilienmarkt\Domain\Auth\PasswordHasher;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Search\Fts5SearchIndex;
use Reptilienmarkt\Infra\Search\ListingIndexer;
use Reptilienmarkt\Support\Demo\DemoIndexStrategy;
use Reptilienmarkt\Support\Demo\DemoListingGenerator;
use Reptilienmarkt\Tests\DatabaseTestCase;

/**
 * Die Beispieldaten duerfen im Produktivbetrieb laufen — also muss beweisbar
 * sein, dass sie sich vollstaendig zurueckbauen lassen und dabei keine echte
 * Zeile mitnehmen.
 */
final class DemoListingGeneratorTest extends DatabaseTestCase
{
    private DemoListingGenerator $generator;

    private Fts5SearchIndex $index;

    protected function setUp(): void
    {
        parent::setUp();

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $speciesId = $this->createSpecies();

        $this->database->execute(
            "INSERT INTO morphs (species_id, name, aliases, inheritance, created_at, updated_at)
             VALUES (:species, 'Albino', '[\"Amelanistic\"]', 'recessive', :now, :now)",
            ['species' => $speciesId, 'now' => $now],
        );

        $this->database->execute(
            "INSERT INTO postal_codes (country, postal_code, place_name, lat, lng)
             VALUES ('DE', '10115', 'Berlin', 52.53, 13.38)",
        );

        $this->index = new Fts5SearchIndex($this->database);
        $this->generator = new DemoListingGenerator(
            $this->database,
            new ListingIndexer($this->database, $this->index),
            $this->index,
            new PdoAuditLog($this->database),
        );
    }

    public function testGeneratesListingsThatBelongToDemoAccounts(): void
    {
        $report = $this->generator->generate(
            count: 12,
            strategy: DemoIndexStrategy::Inkrementell,
            batchSize: 5,
            mediaPaths: ['demo/1.webp'],
            seed: 4711,
        );

        self::assertSame(12, $report->listings);
        self::assertSame(200, $report->users);
        self::assertSame(12, $report->indexed);

        $inventory = $this->generator->inventory();
        self::assertSame(12, $inventory['listings']);
        self::assertSame(200, $inventory['users']);

        // Keine Anzeige darf an einem anderen als einem Beispielkonto haengen.
        self::assertSame(0, $this->scalarInt(
            "SELECT COUNT(*) FROM listings WHERE user_id NOT IN (SELECT id FROM users WHERE email_canonical LIKE 'demo%@example.tld')",
        ));

        self::assertSame(12, $this->scalarInt(
            'SELECT COUNT(*) FROM listings WHERE title LIKE :prefix',
            ['prefix' => DemoListingGenerator::TITLE_PREFIX . ' %'],
        ));

        self::assertSame(12, $this->index->count());
    }

    public function testWithoutMediaPathsNoImageRowsAppear(): void
    {
        $report = $this->generator->generate(count: 8, batchSize: 8, seed: 4711);

        self::assertSame(0, $report->media);
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM listing_media'));
    }

    public function testSecondRunAddsListingsAndReusesAccounts(): void
    {
        $this->generator->generate(count: 5, batchSize: 2, seed: 1);
        $second = $this->generator->generate(count: 5, batchSize: 2, seed: 2);

        self::assertSame(0, $second->users, 'Die Beispielkonten entstehen nur einmal.');

        $inventory = $this->generator->inventory();
        self::assertSame(10, $inventory['listings']);
        self::assertSame(200, $inventory['users']);

        // Die Nummer im Titel zaehlt weiter, statt bei 1 von vorn zu beginnen.
        self::assertSame(1, $this->scalarInt(
            'SELECT COUNT(*) FROM listings WHERE title LIKE :title',
            ['title' => DemoListingGenerator::TITLE_PREFIX . ' 10 %'],
        ));
    }

    public function testRemoveTakesBackEverythingItCreated(): void
    {
        $this->generator->generate(count: 9, batchSize: 4, mediaPaths: ['demo/1.webp'], seed: 4711);

        self::assertGreaterThan(0, $this->scalarInt('SELECT COUNT(*) FROM listing_morphs'));

        $report = $this->generator->remove(batchSize: 4);

        self::assertSame(9, $report->listings);
        self::assertSame(200, $report->users);

        self::assertSame(['users' => 0, 'listings' => 0], $this->generator->inventory());
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM listing_morphs'));
        self::assertSame(0, $this->scalarInt('SELECT COUNT(*) FROM listing_media'));
        self::assertSame(0, $this->index->count(), 'Der Volltextindex darf keine Karteileichen behalten.');
    }

    public function testRemoveLeavesRealDataAlone(): void
    {
        $userId = $this->createUser('echt@example.tld');
        $speciesId = (int) (string) $this->database->scalar('SELECT id FROM species LIMIT 1');
        $listingId = $this->createListing($userId, $speciesId);

        $indexer = new ListingIndexer($this->database, $this->index);
        $indexer->indexListing($listingId);

        $this->generator->generate(count: 6, batchSize: 3, seed: 4711);
        $this->generator->remove();

        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM listings'));
        self::assertSame($listingId, $this->scalarInt('SELECT id FROM listings'));
        self::assertSame(1, $this->scalarInt('SELECT COUNT(*) FROM users'));
        self::assertSame(1, $this->index->count());
    }

    public function testDemoAccountsCannotBeUsedToLogIn(): void
    {
        $this->generator->generate(count: 1, seed: 4711);

        $hash = (string) $this->database->scalar(
            "SELECT password_hash FROM users WHERE email_canonical = 'demo001@example.tld'",
        );

        self::assertSame(DemoListingGenerator::PASSWORD_HASH, $hash);

        $hasher = new PasswordHasher();
        foreach ([DemoListingGenerator::PASSWORD_HASH, 'beispiel', 'demo', ''] as $versuch) {
            self::assertFalse($hasher->verify($versuch, $hash));
        }
    }

    public function testGeneratedListingsAreFindableInTheFullTextIndex(): void
    {
        $this->generator->generate(count: 4, batchSize: 4, seed: 4711);

        self::assertSame(4, $this->scalarInt(
            "SELECT COUNT(*) FROM listing_search WHERE listing_search MATCH 'Beispielanzeige'",
        ));
    }

    public function testRebuildStrategyIndexesEverything(): void
    {
        $this->generator->generate(count: 7, strategy: DemoIndexStrategy::Neuaufbau, batchSize: 3, seed: 4711);

        self::assertSame(7, $this->index->count());
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function scalarInt(string $sql, array $parameters = []): int
    {
        $value = $this->database->scalar($sql, $parameters);

        return is_numeric($value) ? (int) $value : 0;
    }
}
