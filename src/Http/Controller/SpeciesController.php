<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Search\ListingSearchRepository;
use Reptilienmarkt\Domain\Search\SearchCriteria;
use Reptilienmarkt\Domain\Species\MorphRepository;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Search\SearchUrlContext;
use Reptilienmarkt\Support\Slugger;
use Twig\Environment;

/**
 * Artenprofil als Landingpage: /art/pogona-vitticeps/
 */
final readonly class SpeciesController
{
    public function __construct(
        private SpeciesRepository $species,
        private MorphRepository $morphs,
        private ListingSearchRepository $listings,
        private Environment $twig,
    ) {}

    public function show(Request $request): Response
    {
        $slug = $request->attribute('slug');
        if ($slug === null) {
            return Response::notFound();
        }

        $species = $this->species->findBySlug($slug);

        if ($species === null) {
            // Wer den deutschen Slug erwischt hat, landet dauerhaft auf dem
            // wissenschaftlichen Pfad — eine kanonische Adresse je Art.
            $byCommonSlug = $this->species->findByCommonSlug($slug);
            if ($byCommonSlug !== null) {
                return Response::redirect('/art/' . $byCommonSlug->slug . '/', 301);
            }

            return Response::html($this->twig->render('fehler/404.html.twig', [
                'meldung' => 'Diese Art kennen wir nicht.',
            ]), 404);
        }

        $speciesId = $species->id ?? 0;
        $criteria = new SearchCriteria(speciesId: $speciesId, perPage: 12);
        $result = $this->listings->search($criteria);

        $morphs = $this->morphs->forSpecies($speciesId);
        $morphSlugs = [];
        foreach ($morphs as $morph) {
            if ($morph->id !== null) {
                $morphSlugs[$morph->id] = Slugger::slug($morph->name);
            }
        }

        return Response::html($this->twig->render('art/profil.html.twig', [
            'art' => $species,
            'morphs' => $morphs,
            'ergebnis' => $result,
            'kriterien' => $criteria,
            'url_kontext' => new SearchUrlContext($species, $morphSlugs),
        ]));
    }
}
