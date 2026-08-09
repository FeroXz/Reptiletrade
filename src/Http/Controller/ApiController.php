<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Geo\PostalCodeRepository;
use Reptilienmarkt\Domain\Search\ListingSearchRepository;
use Reptilienmarkt\Domain\Search\ListingSummary;
use Reptilienmarkt\Domain\Species\Morph;
use Reptilienmarkt\Domain\Species\MorphRepository;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Search\SearchRequestParser;
use Reptilienmarkt\Support\Clock;

/**
 * REST-API unter /api/v1/ auf denselben Domain-Services wie die Web-Routen —
 * die Grundlage der spaeteren PWA.
 */
final readonly class ApiController
{
    public function __construct(
        private ListingSearchRepository $listings,
        private SpeciesRepository $species,
        private MorphRepository $morphs,
        private PostalCodeRepository $postalCodes,
        private SearchRequestParser $parser,
        private RateLimiter $rateLimiter,
        private Clock $clock,
    ) {}

    /**
     * Wie lange eine Antwort allgemeingueltig ist.
     *
     * Die Endpunkte sind anonym und liefern fuer alle dasselbe — anders als die
     * HTML-Seiten, die Namen und CSRF-Token tragen. Eine Minute ist kurz genug,
     * dass eine neue Anzeige nicht auffaellig spaet erscheint, und lang genug,
     * dass ein tippendes Vorschlagsfeld nicht jede Taste bis in die Datenbank
     * durchreicht.
     */
    private const int CACHE_SECONDS = 60;

    public function listings(Request $request): Response
    {
        $abgewiesen = $this->guard($request);
        if ($abgewiesen !== null) {
            return $abgewiesen;
        }

        $parsed = $this->parser->parse($request);
        $result = $this->listings->search($parsed->criteria);

        return $this->cached([
            'treffer' => array_map(
                static fn(ListingSummary $listing): array => [
                    'id' => $listing->id,
                    'titel' => $listing->title,
                    'typ' => $listing->type->value,
                    'art' => [
                        'id' => $listing->speciesId,
                        'name' => $listing->speciesCommonName,
                        'slug' => $listing->speciesSlug,
                    ],
                    'morphs' => $listing->morphNames,
                    'preis_cent' => $listing->priceCents,
                    'waehrung' => $listing->currency,
                    'verhandelbar' => $listing->negotiable,
                    'geschlecht' => $listing->sex->value,
                    'herkunft' => $listing->cbStatus->value,
                    'plz' => $listing->postalCode,
                    'land' => $listing->country?->value,
                    'bild' => $listing->imagePath,
                    'entfernung_km' => $listing->distanceKm === null ? null : round($listing->distanceKm, 1),
                    'hervorgehoben' => $listing->isFeatured,
                ],
                $result->listings,
            ),
            'gesamt' => $result->total,
            'seite' => $result->page(),
            'seiten' => $result->pageCount(),
            'facetten' => $result->facets->all(),
            'dauer_ms' => round($result->durationMs, 1),
        ]);
    }

    public function species(Request $request): Response
    {
        $abgewiesen = $this->guard($request);
        if ($abgewiesen !== null) {
            return $abgewiesen;
        }

        $term = $request->queryString('q');
        $species = $term === null ? $this->species->all() : $this->species->search($term);

        return $this->cached([
            'arten' => array_map(
                static fn(Species $entry): array => [
                    'id' => $entry->id,
                    'wissenschaftlich' => $entry->scientificName,
                    'deutsch' => $entry->commonNameDe,
                    'slug' => $entry->slug,
                    'slug_de' => $entry->commonSlug,
                    'eu_anhang' => $entry->euAnnex?->value,
                    'schutzstatus' => $entry->bnatschgStatus->value,
                    'meldepflicht' => $entry->meldepflicht,
                    'gefahrtier' => $entry->gefahrtier,
                ],
                $species,
            ),
        ]);
    }

    public function morphs(Request $request): Response
    {
        $abgewiesen = $this->guard($request);
        if ($abgewiesen !== null) {
            return $abgewiesen;
        }

        $slug = $request->attribute('slug');
        $species = $slug === null ? null : ($this->species->findBySlug($slug) ?? $this->species->findByCommonSlug($slug));

        if ($species === null || $species->id === null) {
            return Response::json(['fehler' => 'Art nicht gefunden'], 404);
        }

        return $this->cached([
            'art' => $species->scientificName,
            'morphs' => array_map(
                static fn(Morph $morph): array => [
                    'id' => $morph->id,
                    'name' => $morph->name,
                    'aliases' => $morph->aliases,
                    'vererbung' => $morph->inheritance->value,
                    'allelgruppe' => $morph->alleleGroup,
                    'letalkombination' => $morph->isLethalCombo,
                ],
                $this->morphs->forSpecies($species->id),
            ),
        ]);
    }

    public function places(Request $request): Response
    {
        $abgewiesen = $this->guard($request);
        if ($abgewiesen !== null) {
            return $abgewiesen;
        }

        $term = $request->queryString('q');
        if ($term === null) {
            return $this->cached(['orte' => []]);
        }

        $countryValue = $request->queryString('land');
        $country = $countryValue === null ? null : Country::tryFrom($countryValue);

        return $this->cached([
            'orte' => array_map(
                static fn($place): array => [
                    'land' => $place->country->value,
                    'plz' => $place->postalCode,
                    'ort' => $place->placeName,
                    'region' => $place->admin1,
                ],
                $this->postalCodes->search($term, $country, 10),
            ),
        ]);
    }

    /**
     * Eine Rate-Grenze je Adresse.
     *
     * Ohne Anmeldung gibt es nichts anderes, woran sie haengen koennte. Sie
     * schuetzt nicht vor einem entschlossenen Angreifer — dafuer braucht es den
     * Webserver davor —, sondern vor dem versehentlichen Dauerlauf: einem
     * Skript in einer Schleife, einem Vorschlagsfeld ohne Entprellung.
     */
    private function guard(Request $request): ?Response
    {
        $entscheidung = $this->rateLimiter->attempt('api.ip', $request->clientIp ?? 'unbekannt');

        if ($entscheidung->allowed) {
            return null;
        }

        return Response::json(['fehler' => $entscheidung->message()], 429)
            ->withHeader('retry-after', (string) $entscheidung->retryAfterSeconds($this->clock->now()));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function cached(array $data): Response
    {
        return Response::json($data)
            ->withHeader('cache-control', 'public, max-age=' . self::CACHE_SECONDS);
    }
}
