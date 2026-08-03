<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\Review\Review;
use Reptilienmarkt\Domain\Review\ReviewService;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Domain\User\AccountException;
use Reptilienmarkt\Domain\User\BreederProfileRepository;
use Reptilienmarkt\Domain\User\BreederProfileService;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Domain\User\UserStatus;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Support\Translator;
use Twig\Environment;

/**
 * Oeffentliches Zuechterprofil und dessen Pflege.
 */
final readonly class ProfileController
{
    public function __construct(
        private BreederProfileRepository $profiles,
        private BreederProfileService $service,
        private ReviewService $reviews,
        private UserRepository $users,
        private SpeciesRepository $species,
        private ListingRepository $listings,
        private Viewer $currentUser,
        private SessionManager $session,
        private Translator $translator,
        private Environment $twig,
    ) {}

    public function show(Request $request): Response
    {
        $slug = $request->attribute('slug') ?? '';
        $profile = $slug === '' ? null : $this->profiles->findBySlug($slug);

        if ($profile === null) {
            throw HttpException::notFound('Dieses Profil gibt es nicht.');
        }

        $owner = $this->users->findById($profile->userId);
        $viewer = $this->currentUser->get();
        $isOwner = $viewer !== null && $viewer->id === $profile->userId;

        if ($owner === null || $owner->status !== UserStatus::Aktiv) {
            throw HttpException::notFound('Dieses Profil gibt es nicht.');
        }

        // Ein nicht oeffentliches Profil sieht nur sein Inhaber selbst.
        if (!$profile->isPublic && !$isOwner) {
            throw HttpException::notFound('Dieses Profil gibt es nicht.');
        }

        $focus = [];
        foreach ($profile->focusSpeciesIds as $speciesId) {
            $species = $this->species->findById($speciesId);
            if ($species !== null) {
                $focus[] = $species;
            }
        }

        return Response::html($this->twig->render('profil/anzeigen.html.twig', [
            'profil' => $profile,
            'inhaber' => $owner,
            'ist_inhaber' => $isOwner,
            'schwerpunkt' => $focus,
            'statistik' => $this->service->statistics($profile->userId),
            'bewertungen' => $this->reviews->summaryFor($profile->userId),
            'letzte_bewertungen' => $this->reviewsFor($profile->userId),
            'anzeigen' => $this->listings->activeForUser($profile->userId),
            'meldungen' => $this->session->takeFlashes(),
            'csrf' => $this->session->csrfToken(),
        ]));
    }

    public function edit(Request $request): Response
    {
        $user = $this->currentUser->require();

        return Response::html($this->twig->render('profil/bearbeiten.html.twig', [
            'profil' => $this->profiles->findByUser($user->id ?? 0),
            'arten' => $this->species->all(),
            'nutzer' => $user,
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function save(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        /** @var mixed $rawFocus */
        $rawFocus = $request->body['schwerpunkt'] ?? [];
        $focus = [];

        if (\is_array($rawFocus)) {
            foreach ($rawFocus as $entry) {
                if (is_numeric($entry)) {
                    $focus[] = (int) $entry;
                }
            }
        }

        $since = $this->input($request, 'zuchtbeginn');

        try {
            $profile = $this->service->save(
                $user,
                $this->nullable($request, 'adresse'),
                $this->nullable($request, 'schlagzeile'),
                $this->nullable($request, 'beschreibung'),
                $focus,
                $since === '' ? null : (int) $since,
                $this->nullable($request, 'website'),
                $this->input($request, 'oeffentlich') !== '',
            );
        } catch (AccountException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/konto/profil');
        }

        $this->session->flash('erfolg', $this->translator->translate('profil.gespeichert'));

        return Response::redirect('/zuechter/' . $profile->slug . '/');
    }

    /**
     * Bewertungen mit dem Namen des Verfassers — der steht nicht in der
     * Bewertung selbst, damit ein umbenanntes Konto nicht alte Namen behaelt.
     *
     * @return list<array{bewertung: Review, autor: string}>
     */
    private function reviewsFor(int $userId): array
    {
        $entries = [];

        foreach ($this->reviews->recent($userId, 10) as $review) {
            $author = $this->users->findById($review->fromUserId);

            $entries[] = [
                'bewertung' => $review,
                'autor' => $author === null ? 'Gelöschtes Konto' : $author->displayName,
            ];
        }

        return $entries;
    }

    private function nullable(Request $request, string $name): ?string
    {
        $value = $this->input($request, $name);

        return $value === '' ? null : $value;
    }

    private function input(Request $request, string $name): string
    {
        $value = $request->body[$name] ?? '';

        return \is_string($value) ? trim($value) : '';
    }

    private function guardCsrf(Request $request): void
    {
        $this->session->assertCsrf($request);
    }
}
