<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Listing\FavoriteRepository;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Translator;
use Twig\Environment;

/**
 * Die Merkliste.
 *
 * Merken und Entmerken sind echte Formulare mit echter Weiterleitung. Das
 * Skript unter public/assets/merkliste.js tauscht nur den Knopf, wenn es
 * geladen ist — dafuer beantwortet dieselbe Aktion eine JSON-Anfrage. Ohne
 * JavaScript aendert sich nichts ausser einem Seitenaufbau.
 */
final readonly class FavoriteController
{
    public function __construct(
        private FavoriteRepository $favorites,
        private ListingRepository $listings,
        private Viewer $currentUser,
        private SessionManager $session,
        private Translator $translator,
        private Clock $clock,
        private Environment $twig,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->currentUser->require();

        return Response::html($this->twig->render('konto/merkliste.html.twig', [
            'eintraege' => $this->favorites->forUser($user->id ?? 0),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function add(Request $request): Response
    {
        return $this->toggle($request, true);
    }

    public function remove(Request $request): Response
    {
        return $this->toggle($request, false);
    }

    private function toggle(Request $request, bool $merken): Response
    {
        $user = $this->currentUser->require();
        $this->session->assertCsrf($request);

        $listingId = $this->requireId($request);
        $listing = $this->listings->findById($listingId);

        // 404 wie ueberall, wo eine fremde Kennung geraten werden koennte —
        // auch fuer den Entwurf eines anderen Kontos.
        if ($listing === null || !$listing->status->isPubliclyVisible()) {
            throw HttpException::notFound('Anzeige nicht gefunden.');
        }

        $userId = $user->id ?? 0;

        if ($merken) {
            $this->favorites->add($userId, $listingId, $this->clock->now());
        } else {
            $this->favorites->remove($userId, $listingId);
        }

        if ($request->wantsJson()) {
            return Response::json([
                'gemerkt' => $merken,
                'text' => $this->translator->translate($merken ? 'merkliste.entmerken' : 'merkliste.merken'),
                'aktion' => \sprintf('/anzeige/%d/%s', $listingId, $merken ? 'entmerken' : 'merken'),
            ]);
        }

        $this->session->flash('erfolg', $this->translator->translate(
            $merken ? 'merkliste.gemerkt' : 'merkliste.entfernt',
        ));

        return Response::redirect($this->returnTarget($request, $listingId));
    }

    /**
     * Zurueck, wo der Knopf stand — von der Detailseite auf die Detailseite,
     * aus der Merkliste in die Merkliste. Nur eigene Pfade, nie eine fremde
     * Adresse.
     */
    private function returnTarget(Request $request, int $listingId): string
    {
        $weiter = $request->body['weiter'] ?? '';

        if (\is_string($weiter) && str_starts_with($weiter, '/') && !str_starts_with($weiter, '//')) {
            return $weiter;
        }

        return \sprintf('/anzeige/%d/', $listingId);
    }

    private function requireId(Request $request): int
    {
        $value = $request->attribute('id');

        if ($value === null || !ctype_digit($value)) {
            throw HttpException::notFound('Nicht gefunden.');
        }

        return (int) $value;
    }
}
