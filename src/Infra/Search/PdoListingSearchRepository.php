<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Search;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Listing\CbStatus;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Search\FacetCounts;
use Reptilienmarkt\Domain\Search\ListingSearchRepository;
use Reptilienmarkt\Domain\Search\ListingSummary;
use Reptilienmarkt\Domain\Search\SearchCriteria;
use Reptilienmarkt\Domain\Search\SearchResult;
use Reptilienmarkt\Domain\Search\SortOrder;
use Reptilienmarkt\Infra\Persistence\Database;

/**
 * Facettensuche auf SQLite.
 *
 * Aufbau einer Suche:
 *   1. Trefferseite   — eine Abfrage mit LIMIT
 *   2. Gesamtzahl     — eine COUNT-Abfrage
 *   3. Facetten       — je Dimension eine GROUP-BY-Abfrage ohne die eigene Dimension
 *   4. Merkmalsnamen  — eine Abfrage fuer die Anzeigen der aktuellen Seite
 *
 * Die Abfrageplaene sind in docs/SUCHE.md festgehalten.
 */
final readonly class PdoListingSearchRepository implements ListingSearchRepository
{
    /**
     * @var list<string>
     */
    private const array FACET_DIMENSIONS = [
        FacetCounts::SPECIES,
        FacetCounts::TYPE,
        FacetCounts::SEX,
        FacetCounts::CB_STATUS,
        FacetCounts::COUNTRY,
        FacetCounts::HANDOVER,
    ];

    public function __construct(
        private Database $database,
        private ListingQuery $query,
    ) {}

    public function search(SearchCriteria $criteria): SearchResult
    {
        $start = microtime(true);

        $listings = $this->fetchPage($criteria);
        $total = $this->count($criteria);
        $facets = $this->facets($criteria);

        return new SearchResult(
            $listings,
            $total,
            $facets,
            $criteria,
            (microtime(true) - $start) * 1000,
        );
    }

    public function count(SearchCriteria $criteria): int
    {
        $where = $this->query->conditions($criteria);

        $sql = 'SELECT COUNT(*) FROM listings l '
            . $this->fulltextJoin($where['needsFulltext'])
            . ' WHERE ' . $where['sql'];

        $value = $this->database->scalar($sql, $where['parameters']);

        return (int) (is_numeric($value) ? $value : 0);
    }

    public function morphFacet(SearchCriteria $criteria, int $limit = 40): array
    {
        $where = $this->query->conditions($criteria, FacetCounts::MORPH);

        $sql = 'SELECT lm.morph_id, m.name, lm.zygosity, COUNT(*) AS anzahl
                  FROM listing_morphs lm
                  JOIN morphs m ON m.id = lm.morph_id
                 WHERE lm.listing_id IN (
                       SELECT l.id FROM listings l '
            . $this->fulltextJoin($where['needsFulltext'])
            . ' WHERE ' . $where['sql'] . '
                 )
                 GROUP BY lm.morph_id, lm.zygosity
                 ORDER BY anzahl DESC, m.name
                 LIMIT :facet_limit';

        $parameters = $where['parameters'];
        $parameters['facet_limit'] = $limit;

        $rows = [];
        foreach ($this->database->select($sql, $parameters) as $row) {
            $rows[] = [
                'morph_id' => (int) $row['morph_id'],
                'name' => (string) $row['name'],
                'zygosity' => (string) $row['zygosity'],
                'anzahl' => (int) $row['anzahl'],
            ];
        }

        return $rows;
    }

    /**
     * Abfrageplaene der drei Abfragetypen — Grundlage von docs/SUCHE.md und
     * eine Ruecksicherung gegen unbemerkte Tabellenscans.
     *
     * @return array<string, list<string>>
     */
    public function explain(SearchCriteria $criteria): array
    {
        $page = $this->pageStatement($criteria);
        $where = $this->query->conditions($criteria);

        $countSql = 'SELECT COUNT(*) FROM listings l '
            . $this->fulltextJoin($where['needsFulltext'])
            . ' WHERE ' . $where['sql'];

        $facetWhere = $this->query->conditions($criteria, FacetCounts::SPECIES);
        $facetSql = 'SELECT l.species_id AS wert, COUNT(*) AS anzahl
                       FROM listings l '
            . $this->fulltextJoin($facetWhere['needsFulltext'])
            . ' WHERE ' . $facetWhere['sql'] . ' GROUP BY wert';

        return [
            'seite' => $this->queryPlan($page['sql'], $page['parameters']),
            'anzahl' => $this->queryPlan($countSql, $where['parameters']),
            'facette_art' => $this->queryPlan($facetSql, $facetWhere['parameters']),
        ];
    }

    /**
     * @param array<string, scalar|null> $parameters
     *
     * @return list<string>
     */
    private function queryPlan(string $sql, array $parameters): array
    {
        $plan = [];
        foreach ($this->database->select('EXPLAIN QUERY PLAN ' . $sql, $parameters) as $row) {
            $plan[] = (string) ($row['detail'] ?? '');
        }

        return $plan;
    }

    /**
     * @return array{sql: string, parameters: array<string, scalar|null>}
     */
    private function pageStatement(SearchCriteria $criteria): array
    {
        $where = $this->query->conditions($criteria);

        $distanceColumn = $criteria->hasRadius()
            ? ', ' . ListingQuery::DISTANCE_EXPRESSION . ' AS distance_km'
            : ', NULL AS distance_km';

        $sql = 'SELECT l.id, l.title, l.type, l.species_id, l.price_cents, l.currency, l.negotiable,
                       l.sex, l.cb_status, l.postal_code, l.country, l.is_featured, l.bumped_at, l.created_at,
                       s.common_name_de, s.slug AS species_slug, s.common_slug,
                       pm.path AS image_path, pm.width AS image_width, pm.height AS image_height,
                       pm.variant_widths AS image_variant_widths'
            . $distanceColumn . '
                  FROM listings l
                  JOIN species s ON s.id = l.species_id
                  LEFT JOIN listing_media pm ON pm.listing_id = l.id AND pm.is_primary = 1 '
            . $this->fulltextJoin($where['needsFulltext'])
            . ' WHERE ' . $where['sql']
            . ' ORDER BY ' . $this->orderBy($criteria->effectiveSort())
            . ' LIMIT :page_limit OFFSET :page_offset';

        $parameters = $where['parameters'];
        $parameters['page_limit'] = $criteria->limit();
        $parameters['page_offset'] = $criteria->offset();

        return ['sql' => $sql, 'parameters' => $parameters];
    }

    /**
     * @return list<ListingSummary>
     */
    private function fetchPage(SearchCriteria $criteria): array
    {
        $statement = $this->pageStatement($criteria);

        $rows = $this->database->select($statement['sql'], $statement['parameters']);
        if ($rows === []) {
            return [];
        }

        $morphNames = $this->morphNamesFor(array_map(static fn(array $row): int => (int) $row['id'], $rows));

        return array_map(
            fn(array $row): ListingSummary => $this->toSummary($row, $morphNames[(int) $row['id']] ?? []),
            $rows,
        );
    }

    private function facets(SearchCriteria $criteria): FacetCounts
    {
        $counts = [];
        $labels = [];

        foreach (self::FACET_DIMENSIONS as $dimension) {
            $where = $this->query->conditions($criteria, $dimension);

            $column = match ($dimension) {
                FacetCounts::SPECIES => 'l.species_id',
                FacetCounts::TYPE => 'l.type',
                FacetCounts::SEX => 'l.sex',
                FacetCounts::CB_STATUS => 'l.cb_status',
                FacetCounts::COUNTRY => 'l.country',
                default => 'l.handover',
            };

            // Bewusst ohne JOIN auf species: Der Join verhindert, dass SQLite den
            // Teilindex als Deckungsindex nutzt (gemessen: 5 ms gegen 70 ms).
            // Die Artnamen werden weiter unten in einer eigenen Abfrage geholt.
            $sql = 'SELECT ' . $column . ' AS wert, COUNT(*) AS anzahl
                      FROM listings l '
                . $this->fulltextJoin($where['needsFulltext'])
                . ' WHERE ' . $where['sql']
                . ' GROUP BY wert ORDER BY anzahl DESC';

            foreach ($this->database->select($sql, $where['parameters']) as $row) {
                if ($row['wert'] === null) {
                    continue;
                }

                $counts[$dimension][(string) $row['wert']] = (int) $row['anzahl'];
            }
        }

        $labels = $this->speciesLabels(array_keys($counts[FacetCounts::SPECIES] ?? []));

        return new FacetCounts($counts, $labels);
    }

    /**
     * Deutsche Namen zu den Art-IDs der Facette. Eine kleine Nachschlagabfrage
     * ist billiger als ein JOIN in jeder Facettenabfrage.
     *
     * @param list<array-key> $speciesIds
     *
     * @return array<string, string>
     */
    private function speciesLabels(array $speciesIds): array
    {
        if ($speciesIds === []) {
            return [];
        }

        $placeholders = [];
        $parameters = [];
        foreach (array_values($speciesIds) as $position => $id) {
            $name = 'sid_' . $position;
            $placeholders[] = ':' . $name;
            $parameters[$name] = (int) $id;
        }

        $labels = [];
        foreach ($this->database->select(
            'SELECT id, common_name_de FROM species WHERE id IN (' . implode(', ', $placeholders) . ')',
            $parameters,
        ) as $row) {
            $labels[(string) $row['id']] = (string) $row['common_name_de'];
        }

        return $labels;
    }

    /**
     * Merkmalsnamen fuer die Anzeigen einer Seite — eine Abfrage statt N+1.
     *
     * @param list<int> $listingIds
     *
     * @return array<int, list<string>>
     */
    private function morphNamesFor(array $listingIds): array
    {
        if ($listingIds === []) {
            return [];
        }

        $placeholders = [];
        $parameters = [];
        foreach ($listingIds as $position => $id) {
            $name = 'lid_' . $position;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $id;
        }

        $sql = 'SELECT lm.listing_id, m.name, lm.zygosity
                  FROM listing_morphs lm
                  JOIN morphs m ON m.id = lm.morph_id
                 WHERE lm.listing_id IN (' . implode(', ', $placeholders) . ')
                 ORDER BY lm.listing_id, m.name';

        $names = [];
        foreach ($this->database->select($sql, $parameters) as $row) {
            $listingId = (int) $row['listing_id'];
            $label = (string) $row['name'];

            // Nicht sichtbare Auspraegungen werden gekennzeichnet; der
            // vollstaendige Morph-String entsteht in Phase 4.
            $zygosity = (string) $row['zygosity'];
            if ($zygosity !== 'visual') {
                $label = match ($zygosity) {
                    'het' => 'het ' . $label,
                    'poss_het_66' => '66% poss. het ' . $label,
                    'poss_het_50' => '50% poss. het ' . $label,
                    default => $label,
                };
            }

            $names[$listingId][] = $label;
        }

        return $names;
    }

    private function orderBy(SortOrder $sort): string
    {
        return match ($sort) {
            // is_featured zuerst: die Boost-Platzierung aus Phase 6.
            // Entspricht exakt idx_listings_rank — deshalb ohne Sortierschritt.
            SortOrder::Neueste => 'l.is_featured DESC, l.bumped_at DESC, l.id DESC',
            SortOrder::PreisAufsteigend => 'l.price_cents IS NULL, l.price_cents ASC, l.id DESC',
            SortOrder::PreisAbsteigend => 'l.price_cents IS NULL, l.price_cents DESC, l.id DESC',
            SortOrder::Entfernung => 'distance_km ASC, l.id DESC',
            SortOrder::Relevanz => 'bm25(listing_search) ASC, l.id DESC',
        };
    }

    private function fulltextJoin(bool $needed): string
    {
        return $needed ? 'JOIN listing_search ON listing_search.rowid = l.id ' : '';
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $morphNames
     */
    private function toSummary(array $row, array $morphNames): ListingSummary
    {
        $country = $row['country'];
        $bumped = $row['bumped_at'] ?? $row['created_at'];

        return new ListingSummary(
            (int) $row['id'],
            (string) $row['title'],
            ListingType::from((string) $row['type']),
            (int) $row['species_id'],
            (string) $row['common_name_de'],
            (string) $row['species_slug'],
            $row['common_slug'] === null ? null : (string) $row['common_slug'],
            $morphNames,
            $row['price_cents'] === null ? null : (int) $row['price_cents'],
            (string) $row['currency'],
            (bool) $row['negotiable'],
            Sex::from((string) $row['sex']),
            CbStatus::from((string) $row['cb_status']),
            $row['postal_code'] === null ? null : (string) $row['postal_code'],
            \is_string($country) ? Country::tryFrom($country) : null,
            $row['image_path'] === null ? null : (string) $row['image_path'],
            $row['distance_km'] === null ? null : (float) $row['distance_km'],
            (bool) $row['is_featured'],
            \is_string($bumped) && $bumped !== '' ? new DateTimeImmutable($bumped) : null,
            // Fuer width/height am img-Tag: Ohne sie springt das Layout, sobald
            // das Bild ankommt.
            ($row['image_width'] ?? null) === null ? null : (int) $row['image_width'],
            ($row['image_height'] ?? null) === null ? null : (int) $row['image_height'],
            // Denormalisiert wie is_featured: Ohne diese Spalte muesste srcset
            // je Kachel drei Dateien auf der Platte suchen.
            ($row['image_variant_widths'] ?? null) === null ? null : (string) $row['image_variant_widths'],
        );
    }
}
