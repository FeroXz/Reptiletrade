<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Geo;

interface PostalCodeRepository
{
    public function find(Country $country, string $postalCode): ?PostalCode;

    /**
     * Postleitzahlen im Rechteck — der indexgestuetzte Vorfilter der Umkreissuche.
     *
     * @return list<PostalCode>
     */
    public function withinBoundingBox(BoundingBox $box, ?Country $country = null): array;

    /**
     * Autocomplete fuer die Standortangabe (PLZ oder Ortsname).
     *
     * @return list<PostalCode>
     */
    public function search(string $term, ?Country $country = null, int $limit = 20): array;

    public function count(?Country $country = null): int;

    /**
     * Massenimport. Ersetzt vorhandene Eintraege derselben (country, postal_code).
     *
     * @param iterable<PostalCode> $postalCodes
     *
     * @return int Anzahl geschriebener Zeilen
     */
    public function upsertMany(iterable $postalCodes): int;
}
