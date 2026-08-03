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
use Reptilienmarkt\Domain\Search\SearchRadius;
use Reptilienmarkt\Domain\Search\SortOrder;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Search\SearchRequestParser;
use Twig\Environment;

final readonly class MarketController
{
    public function __construct(
        private ListingSearchRepository $listings,
        private SearchRequestParser $parser,
        private Environment $twig,
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
