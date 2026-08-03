<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Breeding\AnnouncementException;
use Reptilienmarkt\Domain\Breeding\AnnouncementStatus;
use Reptilienmarkt\Domain\Breeding\BreedingAnnouncementRepository;
use Reptilienmarkt\Domain\Breeding\BreedingAnnouncementService;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Twig\Environment;

/**
 * Nachzucht-Ankuendigungen. Merkmal des Zuechter-Tarifs; bei abgeschalteter
 * Monetarisierung steht es allen offen.
 */
final readonly class AnnouncementController
{
    public function __construct(
        private BreedingAnnouncementService $announcements,
        private BreedingAnnouncementRepository $repository,
        private SpeciesRepository $species,
        private Viewer $currentUser,
        private SessionManager $session,
        private Environment $twig,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->currentUser->require();

        return Response::html($this->twig->render('nachzucht/liste.html.twig', [
            'ankuendigungen' => $this->announcements->forUser($user),
            'arten' => $this->species->all(),
            'darf' => $this->announcements->mayAnnounce($user),
            'zustaende' => AnnouncementStatus::cases(),
            'artnamen' => $this->speciesNames(),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function create(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        try {
            $this->announcements->create(
                $user,
                (int) $this->input($request, 'art_id'),
                $this->input($request, 'titel'),
                $this->nullable($request, 'beschreibung'),
                $this->nullable($request, 'erwartet_am'),
                $this->nullable($request, 'verpaarung'),
            );

            $this->session->flash('erfolg', 'Die Ankündigung ist als Entwurf angelegt.');
        } catch (AnnouncementException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect('/konto/nachzuchten');
    }

    public function changeStatus(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        $id = $request->attribute('id');
        if ($id === null || !ctype_digit($id)) {
            throw HttpException::notFound('Nicht gefunden.');
        }

        $announcement = $this->repository->findById((int) $id);
        $status = AnnouncementStatus::tryFrom($this->input($request, 'status'));

        // 404 statt 403 — eine fremde Ankuendigung soll sich nicht verraten.
        if ($announcement === null || !$announcement->belongsTo($user->id ?? 0)) {
            throw HttpException::notFound('Nicht gefunden.');
        }

        if ($status === null) {
            $this->session->flash('fehler', 'Unbekannter Zustand.');

            return Response::redirect('/konto/nachzuchten');
        }

        try {
            $this->announcements->changeStatus($announcement, $user, $status);
            $this->session->flash('erfolg', 'Gespeichert.');
        } catch (AnnouncementException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect('/konto/nachzuchten');
    }

    /**
     * @return array<int, string>
     */
    private function speciesNames(): array
    {
        $names = [];

        foreach ($this->species->all() as $species) {
            if ($species->id !== null) {
                $names[$species->id] = $species->commonNameDe;
            }
        }

        return $names;
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
