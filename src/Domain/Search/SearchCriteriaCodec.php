<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

use Reptilienmarkt\Domain\Geo\Coordinates;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Listing\CbStatus;
use Reptilienmarkt\Domain\Listing\Handover;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Listing\Zygosity;

/**
 * Uebersetzt Suchkriterien in die Ablage und zurueck.
 *
 * Gespeichert werden die **Kriterien**, nicht die URL, unter der sie einmal
 * standen. Eine gespeicherte URL waere an das aktuelle Adressschema gebunden:
 * Sobald sich ein Pfadsegment oder ein Parametername aendert — und bei einer
 * Facettensuche aendert sich so etwas — zeigten alle gespeicherten Suchen ins
 * Leere oder, schlimmer, auf eine andere Suche. Aus Kriterien laesst sich die
 * URL jederzeit neu bauen; aus einer URL lassen sich die Kriterien nur
 * zurueckgewinnen, solange das Schema unveraendert ist.
 *
 * Die Schluessel folgen den Parameternamen der Suchseite. Nicht weil die Ablage
 * daran haengt, sondern weil ein Mensch, der filter_json liest, dann dieselben
 * Woerter sieht wie im Formular.
 *
 * Nicht gespeichert wird die Seitenzahl: Eine gemerkte Suche ist eine Frage,
 * keine Blaetterposition.
 */
final readonly class SearchCriteriaCodec
{
    /**
     * @return array<string, mixed>
     */
    public static function encode(SearchCriteria $criteria): array
    {
        $daten = [];

        if ($criteria->hasQuery()) {
            $daten['q'] = trim((string) $criteria->query);
        }

        if ($criteria->speciesId !== null) {
            $daten['art_id'] = $criteria->speciesId;
        }

        if ($criteria->morphs !== []) {
            $daten['morphs'] = array_map(
                static fn(MorphFilter $morph): array => [
                    'id' => $morph->morphId,
                    'auspraegungen' => $morph->zygosityValues(),
                ],
                $criteria->morphs,
            );
        }

        foreach ([
            'geschlecht' => $criteria->sexes,
            'typ' => $criteria->types,
            'herkunft' => $criteria->cbStatuses,
            'land' => $criteria->countries,
            'uebergabe' => $criteria->handovers,
        ] as $name => $werte) {
            if ($werte !== []) {
                $daten[$name] = array_map(static fn($fall): string => $fall->value, $werte);
            }
        }

        foreach ([
            'preis_min_cent' => $criteria->priceMinCents,
            'preis_max_cent' => $criteria->priceMaxCents,
            'alter_min' => $criteria->ageMinMonths,
            'alter_max' => $criteria->ageMaxMonths,
        ] as $name => $wert) {
            if ($wert !== null) {
                $daten[$name] = $wert;
            }
        }

        if ($criteria->withImageOnly) {
            $daten['mit_bild'] = true;
        }

        if ($criteria->admin1 !== null) {
            $daten['region'] = $criteria->admin1;
        }

        if ($criteria->radius !== null) {
            // Der Mittelpunkt wird mitgeschrieben, obwohl er aus der Postleitzahl
            // abgeleitet ist: Der Alert-Auftrag soll die Suche ausfuehren
            // koennen, ohne dafuer erst wieder eine Ortssuche anzustossen.
            $daten['umkreis'] = [
                'lat' => $criteria->radius->center->latitude,
                'lng' => $criteria->radius->center->longitude,
                'km' => $criteria->radius->radius->value,
                'plz' => $criteria->radius->postalCode,
                'land' => $criteria->radius->country?->value,
            ];
        }

        if ($criteria->sort !== SortOrder::Neueste) {
            $daten['sortierung'] = $criteria->sort->value;
        }

        return $daten;
    }

    /**
     * Baut die Kriterien wieder auf.
     *
     * Durchgehend nachsichtig: Was nicht mehr passt — ein geloeschtes Merkmal,
     * ein umbenannter Aufzaehlungswert — faellt weg, statt die ganze Suche
     * unbrauchbar zu machen. Eine gespeicherte Suche, die nach einer
     * Schemaaenderung etwas weiter fasst, ist besser als eine, die eine
     * Fehlerseite zeigt.
     *
     * @param array<string, mixed> $data
     */
    public static function decode(array $data): SearchCriteria
    {
        return new SearchCriteria(
            query: self::string($data, 'q'),
            speciesId: self::positiveInt($data, 'art_id'),
            morphs: self::morphs($data['morphs'] ?? null),
            sexes: self::enums($data['geschlecht'] ?? null, Sex::class),
            types: self::enums($data['typ'] ?? null, ListingType::class),
            cbStatuses: self::enums($data['herkunft'] ?? null, CbStatus::class),
            countries: self::enums($data['land'] ?? null, Country::class),
            handovers: self::enums($data['uebergabe'] ?? null, Handover::class),
            priceMinCents: self::positiveInt($data, 'preis_min_cent'),
            priceMaxCents: self::positiveInt($data, 'preis_max_cent'),
            ageMinMonths: self::positiveInt($data, 'alter_min'),
            ageMaxMonths: self::positiveInt($data, 'alter_max'),
            withImageOnly: ($data['mit_bild'] ?? false) === true,
            radius: self::radius($data['umkreis'] ?? null),
            admin1: self::string($data, 'region'),
            sort: SortOrder::tryFrom(self::string($data, 'sortierung') ?? '') ?? SortOrder::Neueste,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(array $data, string $key): ?string
    {
        $wert = $data[$key] ?? null;

        if (!\is_string($wert)) {
            return null;
        }

        $wert = trim($wert);

        return $wert === '' ? null : $wert;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function positiveInt(array $data, string $key): ?int
    {
        $wert = $data[$key] ?? null;

        return \is_int($wert) && $wert > 0 ? $wert : null;
    }

    /**
     * @return list<MorphFilter>
     */
    private static function morphs(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $merkmale = [];

        foreach ($raw as $eintrag) {
            if (!\is_array($eintrag) || !\is_int($eintrag['id'] ?? null) || $eintrag['id'] < 1) {
                continue;
            }

            $merkmale[] = new MorphFilter($eintrag['id'], self::enums($eintrag['auspraegungen'] ?? null, Zygosity::class));
        }

        return $merkmale;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return list<T>
     */
    private static function enums(mixed $raw, string $enum): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $faelle = [];

        foreach ($raw as $wert) {
            if (!\is_string($wert)) {
                continue;
            }

            $fall = $enum::tryFrom($wert);

            if ($fall !== null) {
                $faelle[] = $fall;
            }
        }

        return $faelle;
    }

    private static function radius(mixed $raw): ?RadiusFilter
    {
        if (!\is_array($raw)) {
            return null;
        }

        $lat = $raw['lat'] ?? null;
        $lng = $raw['lng'] ?? null;
        $km = $raw['km'] ?? null;

        if (!is_numeric($lat) || !is_numeric($lng) || !\is_int($km)) {
            return null;
        }

        $umkreis = SearchRadius::tryFrom($km);

        if ($umkreis === null) {
            return null;
        }

        $plz = $raw['plz'] ?? null;
        $land = $raw['land'] ?? null;

        return new RadiusFilter(
            new Coordinates((float) $lat, (float) $lng),
            $umkreis,
            \is_string($plz) && $plz !== '' ? $plz : null,
            \is_string($land) ? Country::tryFrom($land) : null,
        );
    }
}
