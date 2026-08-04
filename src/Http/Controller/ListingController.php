<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Listing\ListingMediaRepository;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\Listing\ListingWizard;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Domain\User\BreederProfileRepository;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Twig\Environment;

/**
 * Die oeffentliche Detailseite einer Anzeige.
 */
final readonly class ListingController
{
    private const string SEEN_KEY = '_gesehen';

    private const int SEEN_LIMIT = 50;

    public function __construct(
        private ListingRepository $listings,
        private ListingMediaRepository $media,
        private SpeciesRepository $species,
        private ListingWizard $wizard,
        private UserRepository $users,
        private BreederProfileRepository $profiles,
        private SessionManager $session,
        private Viewer $currentUser,
        private Environment $twig,
    ) {}

    public function show(Request $request): Response
    {
        $id = $request->attribute('id');
        if ($id === null || !ctype_digit($id)) {
            throw HttpException::notFound('Anzeige nicht gefunden.');
        }

        $listing = $this->listings->findById((int) $id);
        if ($listing === null) {
            throw HttpException::notFound('Anzeige nicht gefunden.');
        }

        // Entwuerfe und Anzeigen in Pruefung sieht nur, wem sie gehoeren.
        $viewer = $this->currentUser->get();
        $istEigene = $viewer !== null && $listing->belongsTo($viewer->id ?? 0);

        if (!$listing->status->isPubliclyVisible() && !$istEigene && !($viewer?->role->mayModerate() ?? false)) {
            throw HttpException::notFound('Anzeige nicht gefunden.');
        }

        $species = $this->species->findById($listing->speciesId);
        if ($species === null) {
            throw HttpException::notFound('Anzeige nicht gefunden.');
        }

        $this->countView($listing->id ?? 0, $istEigene);

        return Response::html($this->twig->render('anzeige/detail.html.twig', [
            'listing' => $listing,
            'art' => $species,
            'medien' => $this->media->forListing($listing->id ?? 0),
            'morph_string' => $this->wizard->morphString($listing->id ?? 0),
            'genotyp' => $this->wizard->genotype($listing->id ?? 0),
            'ist_eigene' => $istEigene,
            'anbieter' => $this->users->findById($listing->userId),
            'anbieter_profil' => $this->profiles->findByUser($listing->userId),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }
    /**
     * Zaehlt den Aufruf — einmal je Sitzung und Anzeige.
     *
     * Ohne Entprellung zaehlt jedes Neuladen mit, und die Zahl sagt dem
     * Anbieter nichts mehr. Eigene Aufrufe zaehlen gar nicht: Wer die eigene
     * Anzeige zehnmal am Tag kontrolliert, soll sich die Zahl nicht selbst
     * schoenrechnen.
     *
     * Gemerkt wird in der Sitzung, gedeckelt auf die zuletzt gesehenen
     * Anzeigen — sonst waechst die Nutzlast der Sitzung unbegrenzt.
     */
    private function countView(int $listingId, bool $istEigene): void
    {
        if ($listingId === 0 || $istEigene) {
            return;
        }

        /** @var mixed $gesehen */
        $gesehen = $this->session->get(self::SEEN_KEY, []);
        $ids = [];

        if (\is_array($gesehen)) {
            foreach ($gesehen as $eintrag) {
                if (\is_int($eintrag)) {
                    $ids[] = $eintrag;
                }
            }
        }

        if (\in_array($listingId, $ids, true)) {
            return;
        }

        $ids[] = $listingId;
        $this->session->put(self::SEEN_KEY, \array_slice($ids, -self::SEEN_LIMIT));
        $this->listings->recordView($listingId);
    }
}
