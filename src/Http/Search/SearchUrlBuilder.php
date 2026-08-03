<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Search;

use Reptilienmarkt\Domain\Search\MorphFilter;
use Reptilienmarkt\Domain\Search\SearchCriteria;
use Reptilienmarkt\Domain\Search\SortOrder;

/**
 * Erzeugt aus Suchkriterien wieder eine URL.
 *
 * Art, Merkmale und Region wandern in den Pfad — daraus entsteht
 * /markt/bartagame/red-hypo-translucent/bayern/. Alles Weitere bleibt
 * Query-Parameter. Der Pfad wird nur gebaut, wenn er verlustfrei ist:
 * Sobald eine Auspraegung mitgefiltert wird oder ein Merkmalsslug fehlt,
 * gehen die Merkmale in die Query, damit die URL wieder dieselbe Suche ergibt.
 */
final class SearchUrlBuilder
{
    public static function build(SearchCriteria $criteria, SearchUrlContext $context): string
    {
        $segments = [];
        $query = [];

        $speciesSegment = $criteria->speciesId !== null && $criteria->speciesId === $context->species?->id
            ? $context->speciesSegment()
            : null;

        if ($speciesSegment !== null) {
            $segments[] = $speciesSegment;
        } elseif ($criteria->speciesId !== null) {
            $query['art_id'] = (string) $criteria->speciesId;
        }

        $morphSegment = null;
        if ($speciesSegment !== null && $criteria->morphs !== [] && self::morphsArePathSafe($criteria->morphs)) {
            $morphSegment = $context->morphSegment(
                array_map(static fn(MorphFilter $morph): int => $morph->morphId, $criteria->morphs),
            );
        }

        if ($morphSegment !== null) {
            $segments[] = $morphSegment;
        } else {
            foreach ($criteria->morphs as $morph) {
                $query['morph'][] = $morph->matchesAnyZygosity()
                    ? (string) $morph->morphId
                    : $morph->morphId . ':' . implode('.', $morph->zygosityValues());
            }
        }

        if ($criteria->admin1 !== null) {
            if ($morphSegment !== null && $context->regionSlug !== null) {
                $segments[] = $context->regionSlug;
            } else {
                $query['region'] = $criteria->admin1;
            }
        }

        self::addScalarParameters($query, $criteria);

        $path = '/markt/' . ($segments === [] ? '' : implode('/', $segments) . '/');
        $queryString = self::buildQueryString($query);

        return $queryString === '' ? $path : $path . '?' . $queryString;
    }

    /**
     * @param list<MorphFilter> $morphs
     */
    private static function morphsArePathSafe(array $morphs): bool
    {
        foreach ($morphs as $morph) {
            if (!$morph->matchesAnyZygosity()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string|list<string>> $query
     *
     * @param-out array<string, string|list<string>> $query
     */
    private static function addScalarParameters(array &$query, SearchCriteria $criteria): void
    {
        if ($criteria->hasQuery()) {
            $query['q'] = (string) $criteria->query;
        }

        foreach ([
            'typ' => array_map(static fn($type): string => $type->value, $criteria->types),
            'geschlecht' => array_map(static fn($sex): string => $sex->value, $criteria->sexes),
            'herkunft' => array_map(static fn($status): string => $status->value, $criteria->cbStatuses),
            'land' => array_map(static fn($country): string => $country->value, $criteria->countries),
            'uebergabe' => array_map(static fn($handover): string => $handover->value, $criteria->handovers),
        ] as $name => $values) {
            if ($values !== []) {
                $query[$name] = $values;
            }
        }

        foreach ([
            'preis_min' => $criteria->priceMinCents === null ? null : (string) intdiv($criteria->priceMinCents, 100),
            'preis_max' => $criteria->priceMaxCents === null ? null : (string) intdiv($criteria->priceMaxCents, 100),
            'alter_min' => $criteria->ageMinMonths === null ? null : (string) $criteria->ageMinMonths,
            'alter_max' => $criteria->ageMaxMonths === null ? null : (string) $criteria->ageMaxMonths,
        ] as $name => $value) {
            if ($value !== null) {
                $query[$name] = $value;
            }
        }

        if ($criteria->withImageOnly) {
            $query['mit_bild'] = '1';
        }

        if ($criteria->radius !== null && $criteria->radius->postalCode !== null) {
            $query['plz'] = $criteria->radius->postalCode;
            $query['umkreis'] = (string) $criteria->radius->radius->value;
        }

        if ($criteria->sort !== SortOrder::Neueste) {
            $query['sortierung'] = $criteria->sort->value;
        }

        if ($criteria->page > 1) {
            $query['seite'] = (string) $criteria->page;
        }
    }

    /**
     * @param array<string, string|list<string>> $query
     */
    private static function buildQueryString(array $query): string
    {
        $parts = [];

        foreach ($query as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $entry) {
                    $parts[] = rawurlencode($name) . '=' . rawurlencode($entry);
                }

                continue;
            }

            $parts[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        return implode('&', $parts);
    }
}
