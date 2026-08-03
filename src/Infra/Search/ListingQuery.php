<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Search;

use Reptilienmarkt\Domain\Listing\CbStatus;
use Reptilienmarkt\Domain\Listing\Handover;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Search\FacetCounts;
use Reptilienmarkt\Domain\Search\MorphFilter;
use Reptilienmarkt\Domain\Search\SearchCriteria;
use Reptilienmarkt\Support\Clock;

/**
 * Baut die WHERE-Bedingungen der Suche.
 *
 * Getrennt vom Repository, weil dieselben Bedingungen viermal gebraucht werden:
 * fuer die Trefferseite, die Gesamtzahl, jede Facette (dann ohne die eigene
 * Dimension) und die Merkmalsfacette.
 */
final readonly class ListingQuery
{
    /**
     * Haversine in reinem SQL. Laeuft erst auf dem Ergebnis des
     * Bounding-Box-Vorfilters, nie ueber die ganze Tabelle.
     */
    public const string DISTANCE_EXPRESSION = <<<'SQL'
        (6371.0088 * 2 * asin(min(1.0, sqrt(
            pow(sin(radians(l.lat - CAST(:center_lat AS REAL)) / 2), 2)
            + cos(radians(CAST(:center_lat AS REAL))) * cos(radians(l.lat))
            * pow(sin(radians(l.lng - CAST(:center_lng AS REAL)) / 2), 2)
        ))))
        SQL;

    /**
     * Nur diese Status sind oeffentlich sichtbar.
     */
    private const string VISIBLE_STATUS = "l.status IN ('aktiv','reserviert')";

    public function __construct(private Clock $clock) {}

    /**
     * @param string|null $excludeDimension Facettendimension, die NICHT gefiltert wird
     *
     * @return array{sql: string, parameters: array<string, scalar|null>, needsFulltext: bool}
     */
    public function conditions(SearchCriteria $criteria, ?string $excludeDimension = null): array
    {
        $conditions = [self::VISIBLE_STATUS];
        /** @var array<string, scalar|null> $parameters */
        $parameters = [];
        $needsFulltext = false;

        if ($criteria->hasQuery()) {
            $match = Fts5Query::fromUserInput((string) $criteria->query);
            if ($match !== null) {
                // FTS5 verlangt den Tabellennamen, ein Alias funktioniert hier nicht.
                $conditions[] = 'listing_search MATCH :fts_query';
                $parameters['fts_query'] = $match;
                $needsFulltext = true;
            }
        }

        if ($criteria->speciesId !== null && $excludeDimension !== FacetCounts::SPECIES) {
            $conditions[] = 'l.species_id = :species_id';
            $parameters['species_id'] = $criteria->speciesId;
        }

        if ($excludeDimension !== FacetCounts::TYPE) {
            $this->addEnumFilter(
                $conditions,
                $parameters,
                'l.type',
                'type',
                array_map(static fn(ListingType $type): string => $type->value, $criteria->types),
            );
        }

        if ($excludeDimension !== FacetCounts::SEX) {
            $this->addEnumFilter(
                $conditions,
                $parameters,
                'l.sex',
                'sex',
                array_map(static fn(Sex $sex): string => $sex->value, $criteria->sexes),
            );
        }

        if ($excludeDimension !== FacetCounts::CB_STATUS) {
            $this->addEnumFilter(
                $conditions,
                $parameters,
                'l.cb_status',
                'cb',
                array_map(static fn(CbStatus $status): string => $status->value, $criteria->cbStatuses),
            );
        }

        if ($excludeDimension !== FacetCounts::COUNTRY) {
            $this->addEnumFilter(
                $conditions,
                $parameters,
                'l.country',
                'country',
                array_map(static fn($country): string => $country->value, $criteria->countries),
            );
        }

        if ($excludeDimension !== FacetCounts::HANDOVER) {
            $this->addEnumFilter(
                $conditions,
                $parameters,
                'l.handover',
                'handover',
                array_map(static fn(Handover $handover): string => $handover->value, $criteria->handovers),
            );
        }

        if ($excludeDimension !== FacetCounts::MORPH) {
            $this->addMorphFilters($conditions, $parameters, $criteria->morphs);
        }

        // Das unaere Plus vor price_cents schaltet idx_listings_price fuer diesen
        // Term ab. Grund: Eine Preisspanne ist auf einem Marktplatz kaum
        // trennscharf — die meisten Anzeigen liegen im gewaehlten Band. SQLite
        // haelt den Index nach ANALYZE trotzdem fuer attraktiv und steigt darueber
        // ein, statt ueber Art, Standort oder Volltext. Gemessen an der schwersten
        // Kombination: 38 ms mit Index gegen 6 ms ohne.
        //
        // Das Plus nimmt der Spalte zugleich ihre Typaffinitaet. PDO bindet jeden
        // Parameter als Text, und SQLite sortiert Text ueber jede Zahl — ohne CAST
        // waere der Vergleich immer wahr und der Preisfilter wirkungslos.
        if ($criteria->priceMinCents !== null) {
            $conditions[] = 'l.price_cents IS NOT NULL AND +l.price_cents >= CAST(:price_min AS INTEGER)';
            $parameters['price_min'] = $criteria->priceMinCents;
        }

        if ($criteria->priceMaxCents !== null) {
            $conditions[] = 'l.price_cents IS NOT NULL AND +l.price_cents <= CAST(:price_max AS INTEGER)';
            $parameters['price_max'] = $criteria->priceMaxCents;
        }

        $this->addAgeFilters($conditions, $parameters, $criteria);

        if ($criteria->withImageOnly) {
            $conditions[] = "EXISTS (SELECT 1 FROM listing_media md WHERE md.listing_id = l.id AND md.media_type = 'bild')";
        }

        if ($criteria->admin1 !== null) {
            // Region ueber die PLZ-Tabelle: die Anzeige speichert nur Land und PLZ.
            $conditions[] = <<<'SQL'
                EXISTS (
                    SELECT 1 FROM postal_codes pc
                     WHERE pc.country = l.country AND pc.postal_code = l.postal_code AND pc.admin1 = :admin1
                )
                SQL;
            $parameters['admin1'] = $criteria->admin1;
        }

        $this->addRadiusFilter($conditions, $parameters, $criteria);

        return [
            'sql' => implode(' AND ', $conditions),
            'parameters' => $parameters,
            'needsFulltext' => $needsFulltext,
        ];
    }

    /**
     * @param list<string>               $conditions
     * @param array<string, scalar|null> $parameters
     * @param list<string>               $values
     *
     * @param-out list<string>               $conditions
     * @param-out array<string, scalar|null> $parameters
     */
    private function addEnumFilter(array &$conditions, array &$parameters, string $column, string $prefix, array $values): void
    {
        if ($values === []) {
            return;
        }

        $placeholders = [];
        foreach (array_values($values) as $position => $value) {
            $name = $prefix . '_' . $position;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $value;
        }

        $conditions[] = $column . ' IN (' . implode(', ', $placeholders) . ')';
    }

    /**
     * Mehrere Merkmale werden UND-verknuepft: Wer Hypo und Translucent waehlt,
     * will Tiere, die beides tragen.
     *
     * @param list<string>               $conditions
     * @param array<string, scalar|null> $parameters
     * @param list<MorphFilter>          $morphs
     *
     * @param-out list<string>               $conditions
     * @param-out array<string, scalar|null> $parameters
     */
    private function addMorphFilters(array &$conditions, array &$parameters, array $morphs): void
    {
        foreach ($morphs as $position => $morph) {
            $morphParameter = 'morph_' . $position;
            $parameters[$morphParameter] = $morph->morphId;

            $zygosityCondition = '';
            if (!$morph->matchesAnyZygosity()) {
                $placeholders = [];
                foreach ($morph->zygosityValues() as $index => $zygosity) {
                    $name = 'zyg_' . $position . '_' . $index;
                    $placeholders[] = ':' . $name;
                    $parameters[$name] = $zygosity;
                }
                $zygosityCondition = ' AND lm.zygosity IN (' . implode(', ', $placeholders) . ')';
            }

            $conditions[] = \sprintf(
                'EXISTS (SELECT 1 FROM listing_morphs lm WHERE lm.listing_id = l.id AND lm.morph_id = :%s%s)',
                $morphParameter,
                $zygosityCondition,
            );
        }
    }

    /**
     * Alter wird nicht gerechnet, sondern in Datumsgrenzen uebersetzt — so
     * bleibt der Index auf hatch_date nutzbar.
     *
     * @param list<string>               $conditions
     * @param array<string, scalar|null> $parameters
     *
     * @param-out list<string>               $conditions
     * @param-out array<string, scalar|null> $parameters
     */
    private function addAgeFilters(array &$conditions, array &$parameters, SearchCriteria $criteria): void
    {
        $now = $this->clock->now();

        if ($criteria->ageMinMonths !== null) {
            // Mindestens X Monate alt = spaetestens vor X Monaten geschluepft.
            $conditions[] = 'l.hatch_date IS NOT NULL AND l.hatch_date <= :born_before';
            $parameters['born_before'] = $now->modify(\sprintf('-%d months', $criteria->ageMinMonths))->format('Y-m-d');
        }

        if ($criteria->ageMaxMonths !== null) {
            $conditions[] = 'l.hatch_date IS NOT NULL AND l.hatch_date >= :born_after';
            $parameters['born_after'] = $now->modify(\sprintf('-%d months', $criteria->ageMaxMonths))->format('Y-m-d');
        }
    }

    /**
     * Zwei Stufen: erst das Rechteck ueber den Index, dann die exakte Distanz.
     *
     * @param list<string>               $conditions
     * @param array<string, scalar|null> $parameters
     *
     * @param-out list<string>               $conditions
     * @param-out array<string, scalar|null> $parameters
     */
    private function addRadiusFilter(array &$conditions, array &$parameters, SearchCriteria $criteria): void
    {
        $radius = $criteria->radius;
        if ($radius === null) {
            return;
        }

        $box = $radius->boundingBox();

        // CAST ueberall dort, wo nicht direkt eine Spalte verglichen wird: Die
        // Haversine-Formel liefert einen Ausdruck ohne Typaffinitaet, und ein als
        // Text gebundener Parameter waere dagegen immer groesser.
        $conditions[] = 'l.lat IS NOT NULL AND l.lng IS NOT NULL';
        $conditions[] = 'l.lat BETWEEN CAST(:box_min_lat AS REAL) AND CAST(:box_max_lat AS REAL)';
        $conditions[] = 'l.lng BETWEEN CAST(:box_min_lng AS REAL) AND CAST(:box_max_lng AS REAL)';
        $conditions[] = self::DISTANCE_EXPRESSION . ' <= CAST(:radius_km AS REAL)';

        $parameters['box_min_lat'] = $box->minLatitude;
        $parameters['box_max_lat'] = $box->maxLatitude;
        $parameters['box_min_lng'] = $box->minLongitude;
        $parameters['box_max_lng'] = $box->maxLongitude;
        $parameters['center_lat'] = $radius->center->latitude;
        $parameters['center_lng'] = $radius->center->longitude;
        $parameters['radius_km'] = $radius->kilometers();
    }
}
