<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Listing\CbStatus;
use Reptilienmarkt\Domain\Listing\Handover;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Search\FacetCounts;
use Reptilienmarkt\Domain\Search\ListingSearchRepository;
use Reptilienmarkt\Domain\Search\SavedSearchException;
use Reptilienmarkt\Domain\Search\SavedSearchService;
use Reptilienmarkt\Domain\Search\SearchRadius;
use Reptilienmarkt\Domain\Search\SortOrder;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Search\SearchRequestParser;
use Reptilienmarkt\Http\Search\SearchUrlBuilder;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Support\Translator;
use Twig\Environment;

final readonly class MarketController
{
    public function __construct(
        private ListingSearchRepository $listings,
        private SearchRequestParser $parser,
        private SavedSearchService $savedSearches,
        private Viewer $currentUser,
        private SessionManager $session,
        private Translator $translator,
        private Environment $twig,
        private string $appUrl = 'https://example.tld',
    ) {}

    public function home(Request $request): Response
    {
        return Response::redirect('/markt/', 302);
    }

    public function search(Request $request): Response
    {
        $parsed = $this->parser->parse($request);
        $result = $this->listings->search($parsed->criteria);
        $morphFacet = $this->listings->morphFacet($parsed->criteria);

        return Response::html($this->twig->render('markt/suche.html.twig', [
            'ergebnis' => $result,
            'kriterien' => $parsed->criteria,
            'url_kontext' => $parsed->urlContext,
            'facetten' => $result->facets,
            'morph_facette' => $morphFacet,
            'art' => $parsed->urlContext->species,
            'sortierungen' => $this->availableSorts($parsed->criteria->hasRadius(), $parsed->criteria->hasQuery()),
            'typen' => ListingType::cases(),
            'geschlechter' => Sex::cases(),
            'herkuenfte' => CbStatus::cases(),
            'laender' => Country::cases(),
            'uebergaben' => Handover::cases(),
            'umkreise' => SearchRadius::cases(),
            'angemeldet' => $this->currentUser->isAuthenticated(),
            'basis_url' => rtrim($this->appUrl, '/'),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
            'dimension' => [
                'art' => FacetCounts::SPECIES,
                'typ' => FacetCounts::TYPE,
                'geschlecht' => FacetCounts::SEX,
                'herkunft' => FacetCounts::CB_STATUS,
                'land' => FacetCounts::COUNTRY,
                'uebergabe' => FacetCounts::HANDOVER,
            ],
        ]));
    }

    /**
     * "Suche merken" von der Trefferseite.
     *
     * Die Kriterien kommen aus derselben Adresse wie beim Anzeigen — das
     * Formular schickt an den Pfad zurueck, auf dem der Nutzer steht. So gibt
     * es keinen zweiten Weg, aus einer Anfrage Kriterien zu machen, der beim
     * naechsten Filter auseinanderliefe.
     */
    public function remember(Request $request): Response
    {
        $parsed = $this->parser->parse($request);
        $ziel = SearchUrlBuilder::build($parsed->criteria, $parsed->urlContext);

        if (!$this->currentUser->isAuthenticated()) {
            // Mit Ruecksprungziel: Wer sich anmeldet, soll wieder vor seinen
            // Treffern stehen und nicht auf der Startseite.
            return Response::redirect('/anmelden?weiter=' . rawurlencode($ziel));
        }

        $user = $this->currentUser->require();
        $this->session->assertCsrf($request);

        $name = $request->body['name'] ?? '';

        try {
            $this->savedSearches->save($user->id ?? 0, \is_string($name) ? $name : '', $parsed->criteria);
            $this->session->flash('erfolg', $this->translator->translate('suchen.gemerkt'));
        } catch (SavedSearchException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect($ziel);
    }

    /**
     * @return list<SortOrder>
     */
    private function availableSorts(bool $hasRadius, bool $hasQuery): array
    {
        return array_values(array_filter(
            SortOrder::cases(),
            static fn(SortOrder $sort): bool => $sort->isAvailable($hasRadius, $hasQuery),
        ));
    }
}
