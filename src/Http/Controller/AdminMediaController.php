<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Content\ContentException;
use Reptilienmarkt\Domain\Content\ContentPermission;
use Reptilienmarkt\Domain\Content\MediaRepository;
use Reptilienmarkt\Domain\Content\MediaUsageRepository;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Infra\Storage\MediaService;
use Reptilienmarkt\Support\Translator;
use Twig\Environment;

/**
 * Die Mediathek.
 *
 * Loeschen ist zweistufig: Der erste Klick zeigt die Verwendungen, der zweite
 * loescht. Ein Bild, das auf drei Seiten steht, verschwindet nicht auf ein
 * Versehen hin — und wer es trotzdem will, sieht vorher, was er anrichtet.
 */
final readonly class AdminMediaController
{
    private const int PER_PAGE = 48;

    public function __construct(
        private MediaService $media,
        private MediaRepository $repository,
        private MediaUsageRepository $usages,
        private ContentPermission $permission,
        private Viewer $currentUser,
        private SessionManager $session,
        private Translator $translator,
        private Environment $twig,
    ) {}

    public function index(Request $request): Response
    {
        $this->requireEditor();

        $suche = $request->queryString('q');
        $seite = max(1, $request->queryInt('seite', 1) ?? 1);

        $medien = $this->repository->latest(self::PER_PAGE, ($seite - 1) * self::PER_PAGE, $suche);
        $ids = array_values(array_filter(array_map(static fn($m): ?int => $m->id, $medien)));

        return Response::html($this->twig->render('admin/medien.html.twig', [
            'medien' => $medien,
            'verwendungen' => $this->usages->countsFor($ids),
            'gesamt' => $this->repository->count($suche),
            'seite' => $seite,
            'pro_seite' => self::PER_PAGE,
            'suche' => $suche ?? '',
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function upload(Request $request): Response
    {
        $user = $this->requireEditor();
        $this->session->assertCsrf($request);

        $datei = $request->file('bild');

        if ($datei === null || !$datei->isOk()) {
            $this->session->flash('fehler', $datei?->errorMessage() ?? $this->translator->translate('admin.medien.keine_datei'));

            return Response::redirect('/admin/medien');
        }

        try {
            $medium = $this->media->upload($datei->temporaryPath, $datei->clientFilename, $user->id);
        } catch (ContentException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/admin/medien');
        }

        $this->session->flash('erfolg', $this->translator->translate('admin.medien.hochgeladen', [
            'nummer' => $medium->id ?? 0,
        ]));

        return Response::redirect('/admin/medien');
    }

    public function describe(Request $request): Response
    {
        $this->requireEditor();
        $this->session->assertCsrf($request);

        $id = $this->id($request);

        $this->media->describe(
            $id,
            \is_string($request->body['alt_text'] ?? null) ? $request->body['alt_text'] : '',
            \is_string($request->body['bildunterschrift'] ?? null) ? $request->body['bildunterschrift'] : '',
        );

        $this->session->flash('erfolg', $this->translator->translate('admin.medien.beschrieben'));

        return Response::redirect('/admin/medien');
    }

    /**
     * Der erste Schritt: zeigen, wo das Bild steht.
     */
    public function confirmDelete(Request $request): Response
    {
        $this->requireEditor();

        $id = $this->id($request);
        $medium = $this->repository->findById($id);

        if ($medium === null) {
            throw HttpException::notFound('Dieses Bild gibt es nicht.');
        }

        return Response::html($this->twig->render('admin/medium_loeschen.html.twig', [
            'medium' => $medium,
            'verwendungen' => $this->media->usagesOf($id),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function delete(Request $request): Response
    {
        $user = $this->requireEditor();
        $this->session->assertCsrf($request);

        $id = $this->id($request);

        try {
            $this->media->delete($id, $user->id, confirmed: true);
        } catch (ContentException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/admin/medien/' . $id . '/loeschen');
        }

        $this->session->flash('erfolg', $this->translator->translate('admin.medien.geloescht'));

        return Response::redirect('/admin/medien');
    }

    private function id(Request $request): int
    {
        $id = $request->attribute('id');

        if ($id === null || !ctype_digit($id)) {
            throw HttpException::notFound('Dieses Bild gibt es nicht.');
        }

        return (int) $id;
    }

    private function requireEditor(): User
    {
        $user = $this->currentUser->require();

        if (!$this->permission->mayEdit($user)) {
            throw HttpException::notFound('Seite nicht gefunden.');
        }

        return $user;
    }
}
