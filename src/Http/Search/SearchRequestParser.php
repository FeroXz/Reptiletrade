<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Search;

use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Geo\PostalCodeRepository;
use Reptilienmarkt\Domain\Listing\CbStatus;
use Reptilienmarkt\Domain\Listing\Handover;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Listing\Zygosity;
use Reptilienmarkt\Domain\Search\MorphFilter;
use Reptilienmarkt\Domain\Search\RadiusFilter;
use Reptilienmarkt\Domain\Search\SearchCriteria;
use Reptilienmarkt\Domain\Search\SearchRadius;
use Reptilienmarkt\Domain\Search\SortOrder;
use Reptilienmarkt\Domain\Species\Morph;
use Reptilienmarkt\Domain\Species\MorphRepository;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Support\Slugger;

/**
 * Uebersetzt eine HTTP-Anfrage in Suchkriterien.
 *
 * Pfadsegmente und Query-Parameter fuehren zum selben Ergebnis:
 * /markt/bartagame/red-hypo/bayern/ entspricht
 * /markt/?art_id=1&morph=7&morph=12&region=Bayern
 */
final readonly class SearchRequestParser
{
    public function __construct(
        private SpeciesRepository $species,
        private MorphRepository $morphs,
        private PostalCodeRepository $postalCodes,
        private Database $database,
    ) {}

    public function parse(Request $request): ParsedSearch
    {
        $species = $this->resolveSpecies($request);
        $morphFilters = $this->resolveMorphs($request, $species);
        $admin1 = $this->resolveRegion($request);
        $radius = $this->resolveRadius($request);

        $criteria = new SearchCriteria(
            query: $request->queryString('q'),
            speciesId: $species?->id,
            morphs: $morphFilters,
            sexes: $this->enums($request->queryList('geschlecht'), Sex::class),
            types: $this->enums($request->queryList('typ'), ListingType::class),
            cbStatuses: $this->enums($request->queryList('herkunft'), CbStatus::class),
            countries: $this->enums($request->queryList('land'), Country::class),
            handovers: $this->enums($request->queryList('uebergabe'), Handover::class),
            priceMinCents: $this->euroToCents($request->queryInt('preis_min')),
            priceMaxCents: $this->euroToCents($request->queryInt('preis_max')),
            ageMinMonths: $this->positive($request->queryInt('alter_min')),
            ageMaxMonths: $this->positive($request->queryInt('alter_max')),
            withImageOnly: $request->queryBool('mit_bild'),
            radius: $radius,
            admin1: $admin1,
            sort: SortOrder::tryFrom($request->queryString('sortierung', '') ?? '') ?? SortOrder::Neueste,
            page: max(1, $request->queryInt('seite', 1) ?? 1),
            perPage: 24,
        );

        return new ParsedSearch($criteria, new SearchUrlContext(
            $species,
            $species === null ? [] : $this->morphSlugMap($species->id ?? 0),
            $admin1 === null ? null : Slugger::slug($admin1),
        ));
    }

    private function resolveSpecies(Request $request): ?Species
    {
        $pathSlug = $request->attribute('art');
        if ($pathSlug !== null) {
            return $this->species->findByCommonSlug($pathSlug) ?? $this->species->findBySlug($pathSlug);
        }

        $id = $request->queryInt('art_id');
        if ($id !== null) {
            return $this->species->findById($id);
        }

        $slug = $request->queryString('art');

        return $slug === null ? null : ($this->species->findByCommonSlug($slug) ?? $this->species->findBySlug($slug));
    }

    /**
     * @return list<MorphFilter>
     */
    private function resolveMorphs(Request $request, ?Species $species): array
    {
        $pathSegment = $request->attribute('morphs');
        if ($pathSegment !== null && $species !== null && $species->id !== null) {
            return $this->morphsFromSlug($pathSegment, $species->id);
        }

        $filters = [];
        foreach ($request->queryList('morph') as $entry) {
            // Format: "12" oder "12:visual.het"
            $parts = explode(':', $entry, 2);
            if (!is_numeric($parts[0])) {
                continue;
            }

            $zygosities = [];
            if (isset($parts[1])) {
                foreach (explode('.', $parts[1]) as $value) {
                    $zygosity = Zygosity::tryFrom($value);
                    if ($zygosity !== null) {
                        $zygosities[] = $zygosity;
                    }
                }
            }

            $filters[] = new MorphFilter((int) $parts[0], $zygosities);
        }

        return $filters;
    }

    /**
     * Zerlegt "red-hypo-translucent" in die passenden Merkmale. Es wird immer
     * der laengste bekannte Slug zuerst probiert, damit "hypo-translucent"
     * nicht faelschlich als "hypo" plus Rest zerfaellt.
     *
     * @return list<MorphFilter>
     */
    private function morphsFromSlug(string $segment, int $speciesId): array
    {
        $slugMap = $this->morphSlugLookup($speciesId);
        if ($slugMap === []) {
            return [];
        }

        $known = array_keys($slugMap);
        usort($known, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $filters = [];
        $rest = $segment;

        while ($rest !== '') {
            $matched = null;

            foreach ($known as $slug) {
                if ($rest === $slug) {
                    $matched = $slug;
                    $rest = '';

                    break;
                }

                if (str_starts_with($rest, $slug . '-')) {
                    $matched = $slug;
                    $rest = substr($rest, \strlen($slug) + 1);

                    break;
                }
            }

            if ($matched === null) {
                // Unbekanntes Segment: die Suche laeuft ohne dieses Merkmal weiter,
                // statt eine 404 zu werfen.
                break;
            }

            $filters[] = new MorphFilter($slugMap[$matched]);
        }

        return $filters;
    }

    /**
     * Slug -> Merkmals-ID, inklusive Aliases: "pied" findet "Piebald".
     *
     * @return array<string, int>
     */
    private function morphSlugLookup(int $speciesId): array
    {
        $lookup = [];

        foreach ($this->morphs->forSpecies($speciesId) as $morph) {
            foreach ([$morph->name, ...$morph->aliases] as $label) {
                $slug = Slugger::slug($label);
                if ($slug !== '' && !isset($lookup[$slug]) && $morph->id !== null) {
                    $lookup[$slug] = $morph->id;
                }
            }
        }

        return $lookup;
    }

    /**
     * Merkmals-ID -> kanonischer Slug fuer den Pfadbau.
     *
     * @return array<int, string>
     */
    private function morphSlugMap(int $speciesId): array
    {
        $map = [];
        foreach ($this->morphs->forSpecies($speciesId) as $morph) {
            if ($morph->id !== null) {
                $map[$morph->id] = Slugger::slug($morph->name);
            }
        }

        return $map;
    }

    /**
     * "bayern" -> "Bayern". Die Zuordnung stammt aus der PLZ-Tabelle, damit
     * jede Region existiert, in der es auch Anzeigen geben kann.
     */
    private function resolveRegion(Request $request): ?string
    {
        $value = $request->attribute('region') ?? $request->queryString('region');
        if ($value === null) {
            return null;
        }

        $slug = Slugger::slug($value);

        foreach ($this->database->select('SELECT DISTINCT admin1 FROM postal_codes WHERE admin1 IS NOT NULL') as $row) {
            $admin1 = (string) $row['admin1'];
            if (Slugger::slug($admin1) === $slug) {
                return $admin1;
            }
        }

        return null;
    }

    private function resolveRadius(Request $request): ?RadiusFilter
    {
        $postalCode = $request->queryString('plz');
        $radiusValue = $request->queryInt('umkreis');

        if ($postalCode === null || $radiusValue === null) {
            return null;
        }

        $radius = SearchRadius::tryFrom($radiusValue);
        if ($radius === null) {
            return null;
        }

        $countryValue = $request->queryString('land_plz');
        $countries = $countryValue !== null && Country::tryFrom($countryValue) !== null
            ? [Country::from($countryValue)]
            : Country::cases();

        foreach ($countries as $country) {
            $entry = $this->postalCodes->find($country, $postalCode);
            if ($entry !== null) {
                return new RadiusFilter($entry->coordinates, $radius, $entry->postalCode, $country, $entry->placeName);
            }
        }

        return null;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param list<string>    $values
     * @param class-string<T> $enum
     *
     * @return list<T>
     */
    private function enums(array $values, string $enum): array
    {
        $result = [];
        foreach ($values as $value) {
            $case = $enum::tryFrom($value);
            if ($case !== null && !\in_array($case, $result, true)) {
                $result[] = $case;
            }
        }

        return $result;
    }

    private function euroToCents(?int $euro): ?int
    {
        return $euro === null || $euro < 0 ? null : $euro * 100;
    }

    private function positive(?int $value): ?int
    {
        return $value === null || $value < 0 ? null : $value;
    }
}
