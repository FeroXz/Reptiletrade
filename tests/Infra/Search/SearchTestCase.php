<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Search;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Search\ListingSummary;
use Reptilienmarkt\Domain\Search\SearchResult;
use Reptilienmarkt\Infra\Search\Fts5SearchIndex;
use Reptilienmarkt\Infra\Search\ListingIndexer;
use Reptilienmarkt\Infra\Search\ListingQuery;
use Reptilienmarkt\Infra\Search\PdoListingSearchRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

abstract class SearchTestCase extends DatabaseTestCase
{
    protected const string NOW = '2026-08-02T12:00:00+00:00';

    protected PdoListingSearchRepository $repository;

    protected ListingIndexer $indexer;

    protected FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable(self::NOW));
        $this->repository = new PdoListingSearchRepository($this->database, new ListingQuery($this->clock));
        $this->indexer = new ListingIndexer($this->database, new Fts5SearchIndex($this->database));
    }

    protected function createSpeciesNamed(string $scientificName, string $commonName): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $slug = strtolower(str_replace(' ', '-', $scientificName));

        $this->database->execute(
            'INSERT INTO species (scientific_name, common_name_de, bnatschg_status, slug, common_slug, created_at, updated_at)
             VALUES (:name, :common, :status, :slug, :common_slug, :now, :now)',
            [
                'name' => $scientificName,
                'common' => $commonName,
                'status' => 'nicht_geschuetzt',
                'slug' => $slug,
                'common_slug' => strtolower(str_replace(' ', '-', $commonName)),
                'now' => $now,
            ],
        );

        return $this->database->lastInsertId();
    }

    /**
     * @param list<string> $aliases
     */
    protected function createMorph(int $speciesId, string $name, array $aliases = []): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->database->execute(
            'INSERT INTO morphs (species_id, name, aliases, inheritance, created_at, updated_at)
             VALUES (:species_id, :name, :aliases, :inheritance, :now, :now)',
            [
                'species_id' => $speciesId,
                'name' => $name,
                'aliases' => json_encode($aliases, \JSON_THROW_ON_ERROR),
                'inheritance' => 'recessive',
                'now' => $now,
            ],
        );

        return $this->database->lastInsertId();
    }

    protected function createPostalCode(string $country, string $postalCode, string $place, string $admin1, float $lat, float $lng): void
    {
        $this->database->execute(
            'INSERT INTO postal_codes (country, postal_code, place_name, admin1, lat, lng, source)
             VALUES (:country, :postal_code, :place, :admin1, :lat, :lng, :source)',
            [
                'country' => $country,
                'postal_code' => $postalCode,
                'place' => $place,
                'admin1' => $admin1,
                'lat' => $lat,
                'lng' => $lng,
                'source' => 'geonames',
            ],
        );
    }

    /**
     * @param array<int, string> $morphs Merkmals-ID -> Zygositaet
     */
    protected function createSearchableListing(
        int $userId,
        int $speciesId,
        string $title = 'Testanzeige',
        string $status = 'aktiv',
        string $type = 'verkauf',
        ?int $priceCents = 10000,
        string $sex = 'w',
        string $cbStatus = 'nz',
        ?string $postalCode = null,
        ?string $country = null,
        ?float $lat = null,
        ?float $lng = null,
        string $handover = 'abholung',
        ?string $hatchDate = null,
        array $morphs = [],
        bool $withImage = false,
        string $description = 'Beschreibung',
        ?string $bumpedAt = null,
        bool $featured = false,
    ): int {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->database->execute(
            'INSERT INTO listings (user_id, type, species_id, title, description, price_cents, currency, sex, cb_status,
                                   status, postal_code, country, lat, lng, handover, hatch_date, created_at, updated_at,
                                   bumped_at, is_featured)
             VALUES (:user_id, :type, :species_id, :title, :description, :price, :currency, :sex, :cb,
                     :status, :postal_code, :country, :lat, :lng, :handover, :hatch_date, :now, :now,
                     :bumped_at, :featured)',
            [
                'user_id' => $userId,
                'type' => $type,
                'species_id' => $speciesId,
                'title' => $title,
                'description' => $description,
                'price' => $priceCents,
                'currency' => 'EUR',
                'sex' => $sex,
                'cb' => $cbStatus,
                'status' => $status,
                'postal_code' => $postalCode,
                'country' => $country,
                'lat' => $lat,
                'lng' => $lng,
                'handover' => $handover,
                'hatch_date' => $hatchDate,
                'now' => $now,
                'bumped_at' => $bumpedAt ?? $now,
                'featured' => $featured ? 1 : 0,
            ],
        );

        $listingId = $this->database->lastInsertId();

        foreach ($morphs as $morphId => $zygosity) {
            $this->database->execute(
                'INSERT INTO listing_morphs (listing_id, morph_id, zygosity) VALUES (:listing_id, :morph_id, :zygosity)',
                ['listing_id' => $listingId, 'morph_id' => $morphId, 'zygosity' => $zygosity],
            );
        }

        if ($withImage) {
            $this->database->execute(
                'INSERT INTO listing_media (listing_id, media_type, path, is_primary, created_at)
                 VALUES (:listing_id, :type, :path, 1, :now)',
                ['listing_id' => $listingId, 'type' => 'bild', 'path' => 'test/' . $listingId . '.webp', 'now' => $now],
            );
        }

        $this->indexer->indexListing($listingId);

        return $listingId;
    }

    /**
     * @return list<int>
     */
    protected function ids(SearchResult $result): array
    {
        return array_map(static fn(ListingSummary $listing): int => $listing->id, $result->listings);
    }

    /**
     * @return list<string>
     */
    protected function titles(SearchResult $result): array
    {
        return array_map(static fn(ListingSummary $listing): string => $listing->title, $result->listings);
    }
}
