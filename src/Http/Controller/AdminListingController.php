<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Listing\ListingManagementException;
use Reptilienmarkt\Domain\Listing\ListingManager;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Twig\Environment;

/**
 * Anzeigenverwaltung: alle Anzeigen sehen, einzelne anhalten und wieder
 * freigeben.
 *
 * Abgegrenzt von der Moderation: Die entscheidet ueber Anzeigen in der
 * Pruefliste und sperrt dauerhaft (Zustand "gesperrt"). Hier geht es um den
 * Eingriff in den laufenden Betrieb — eine Pause ist vorlaeufig und wird
 * zurueckgenommen, sobald die Sache geklaert ist. Deshalb verlangt sie einen
 * Grund, den der Anbieter zu sehen bekommt: Wer nicht erfaehrt, warum seine
 * Anzeige steht, kann es nicht abstellen.
 */
final readonly class AdminListingController
{
    private const int PAGE_SIZE = 100;

    public function __construct(
        private ListingRepository $listings,
        private ListingManager $manager,
        private Viewer $currentUser,
        private SessionManager $session,
        private Environment $twig,
    ) {}

    public function index(Request $request): Response
    {
        $this->requireAdmin();

        $status = $request->queryString('status');
        $nurPausiert = $request->queryBool('nur_pausiert');
        $suche = $request->queryString('suche');

        $filter = [];

        if ($status !== null && ListingStatus::tryFrom($status) !== null) {
            $filter['status'] = $status;
        }

        if ($nurPausiert) {
            $filter['nur_pausiert'] = true;
        }

        if ($suche !== null) {
            $filter['suche'] = $suche;
        }

        return Response::html($this->twig->render('admin/anzeigen.html.twig', [
            'zeilen' => $this->listings->forAdmin($filter, self::PAGE_SIZE),
            'zustaende' => ListingStatus::cases(),
            'bestand' => $this->listings->countsByStatus(),
            'filter' => ['status' => $status, 'nur_pausiert' => $nurPausiert, 'suche' => $suche],
            'grenze' => self::PAGE_SIZE,
            'zurueck' => $this->currentUrl($filter),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function pause(Request $request): Response
    {
        $admin = $this->requireAdmin();
        $this->guardCsrf($request);

        $listing = $this->requireListing($request);
        $grund = $request->body['grund'] ?? '';

        try {
            $this->manager->pauseByAdmin($listing, $admin, \is_string($grund) ? $grund : '');
        } catch (ListingManagementException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect($this->backTo($request));
        }

        $this->session->flash('erfolg', \sprintf('Anzeige #%d pausiert.', $listing->id ?? 0));

        return Response::redirect($this->backTo($request));
    }

    public function resume(Request $request): Response
    {
        $admin = $this->requireAdmin();
        $this->guardCsrf($request);

        $listing = $this->requireListing($request);

        try {
            $this->manager->resumeByAdmin($listing, $admin);
        } catch (ListingManagementException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect($this->backTo($request));
        }

        $this->session->flash('erfolg', \sprintf('Anzeige #%d wieder freigegeben.', $listing->id ?? 0));

        return Response::redirect($this->backTo($request));
    }

    /**
     * Der Filterstand als Adresse — damit eine Massnahme zur gefilterten Liste
     * zurueckfuehrt und nicht auf die nackte Uebersicht.
     *
     * @param array<string, string|bool> $filter
     */
    private function currentUrl(array $filter): string
    {
        $query = [];

        foreach ($filter as $name => $value) {
            $query[$name] = \is_bool($value) ? '1' : $value;
        }

        return '/admin/anzeigen' . ($query === [] ? '' : '?' . http_build_query($query));
    }

    private function requireAdmin(): User
    {
        $user = $this->currentUser->require();

        if ($user->role !== Role::Admin) {
            throw HttpException::notFound('Seite nicht gefunden.');
        }

        return $user;
    }

    private function requireListing(Request $request): \Reptilienmarkt\Domain\Listing\Listing
    {
        $id = $request->attribute('id');

        if ($id === null || !ctype_digit($id)) {
            throw HttpException::notFound('Anzeige nicht gefunden.');
        }

        return $this->listings->findById((int) $id)
            ?? throw HttpException::notFound('Anzeige nicht gefunden.');
    }

    /**
     * Zurueck zur gefilterten Liste, nicht auf die nackte Uebersicht: Wer
     * zwanzig gemeldete Anzeigen durchgeht, will nicht nach jeder wieder
     * filtern.
     */
    private function backTo(Request $request): string
    {
        $ziel = $request->body['zurueck'] ?? '';

        return \is_string($ziel) && str_starts_with($ziel, '/admin/anzeigen')
            ? $ziel
            : '/admin/anzeigen';
    }

    private function guardCsrf(Request $request): void
    {
        $this->session->assertCsrf($request);
    }
}
