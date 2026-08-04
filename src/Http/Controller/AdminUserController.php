<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Contact\ContactRepository;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Domain\User\UserModerationException;
use Reptilienmarkt\Domain\User\UserModerationService;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Domain\User\UserStatus;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Twig\Environment;

/**
 * Konten sperren, entsperren, loeschen — und die Anfragen aus dem
 * Kontaktformular abarbeiten.
 *
 * Eine Sperre verlangt einen Grund, und zwar sichtbar fuer den Betroffenen:
 * Wer nicht erfaehrt, warum er ausgesperrt ist, kann weder nachfragen noch
 * etwas aendern. Befristet ist die Voreinstellung — eine Sperre auf Dauer soll
 * eine Entscheidung sein, kein Nebenprodukt.
 */
final readonly class AdminUserController
{
    private const int PAGE_SIZE = 100;

    /** @var array<string, int> Auswahl in Tagen */
    private const array DURATIONS = ['3 Tage' => 3, '7 Tage' => 7, '30 Tage' => 30, '90 Tage' => 90];

    public function __construct(
        private UserRepository $users,
        private UserModerationService $moderation,
        private ContactRepository $contacts,
        private Viewer $currentUser,
        private SessionManager $session,
        private Environment $twig,
    ) {}

    public function index(Request $request): Response
    {
        $this->requireAdmin();

        $status = $request->queryString('status');
        $suche = $request->queryString('suche');
        $nurGesperrt = $request->queryBool('nur_gesperrt');

        $filter = [];

        if ($status !== null && UserStatus::tryFrom($status) !== null) {
            $filter['status'] = $status;
        }

        if ($nurGesperrt) {
            $filter['nur_gesperrt'] = true;
        }

        if ($suche !== null) {
            $filter['suche'] = $suche;
        }

        return Response::html($this->twig->render('admin/nutzer.html.twig', [
            'zeilen' => $this->users->forAdmin($filter, self::PAGE_SIZE),
            'zustaende' => UserStatus::cases(),
            'dauern' => self::DURATIONS,
            'filter' => ['status' => $status, 'suche' => $suche, 'nur_gesperrt' => $nurGesperrt],
            'grenze' => self::PAGE_SIZE,
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function ban(Request $request): Response
    {
        $admin = $this->requireAdmin();
        $this->session->assertCsrf($request);

        $target = $this->requireUser($request);
        $grund = $request->body['grund'] ?? '';
        $tage = $request->body['tage'] ?? '';

        // "0" oder leer heisst unbefristet — das steht so im Formular.
        $bis = \is_string($tage) && ctype_digit($tage) && (int) $tage > 0
            ? new DateTimeImmutable(\sprintf('+%d days', (int) $tage))
            : null;

        try {
            $this->moderation->ban($target, $admin, \is_string($grund) ? $grund : '', $bis);
        } catch (UserModerationException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/admin/nutzer');
        }

        $this->session->flash('erfolg', $bis === null
            ? \sprintf('%s ist dauerhaft gesperrt.', $target->displayName)
            : \sprintf('%s ist bis zum %s gesperrt.', $target->displayName, $bis->format('d.m.Y')));

        return Response::redirect('/admin/nutzer');
    }

    public function unban(Request $request): Response
    {
        $admin = $this->requireAdmin();
        $this->session->assertCsrf($request);

        $target = $this->requireUser($request);

        try {
            $this->moderation->unban($target, $admin);
        } catch (UserModerationException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/admin/nutzer');
        }

        $this->session->flash('erfolg', \sprintf('%s ist wieder freigeschaltet.', $target->displayName));

        return Response::redirect('/admin/nutzer');
    }

    public function delete(Request $request): Response
    {
        $admin = $this->requireAdmin();
        $this->session->assertCsrf($request);

        $target = $this->requireUser($request);
        $bestaetigung = $request->body['bestaetigung'] ?? '';

        // Dasselbe Wort wie bei der Selbstloeschung. Ein Fehlklick in einer
        // Tabellenzeile darf kein Konto ausloeschen.
        if (!\is_string($bestaetigung) || mb_strtoupper(trim($bestaetigung)) !== 'LÖSCHEN') {
            $this->session->flash('fehler', 'Bitte tippe LÖSCHEN in das Bestätigungsfeld.');

            return Response::redirect('/admin/nutzer');
        }

        try {
            $ergebnis = $this->moderation->delete($target, $admin);
        } catch (UserModerationException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/admin/nutzer');
        }

        $this->session->flash('erfolg', $ergebnis->message());

        return Response::redirect('/admin/nutzer');
    }

    // ------------------------------------------------------------- Kontakt

    public function contactQueue(Request $request): Response
    {
        $this->requireAdmin();

        return Response::html($this->twig->render('admin/kontakt.html.twig', [
            'nachrichten' => $this->contacts->recent(!$request->queryBool('alle'), self::PAGE_SIZE),
            'nur_offen' => !$request->queryBool('alle'),
            'offen' => $this->contacts->openCount(),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function resolveContact(Request $request): Response
    {
        $admin = $this->requireAdmin();
        $this->session->assertCsrf($request);

        $id = $request->attribute('id');

        if ($id === null || !ctype_digit($id) || $this->contacts->find((int) $id) === null) {
            throw HttpException::notFound('Nachricht nicht gefunden.');
        }

        $notiz = $request->body['notiz'] ?? '';
        $this->contacts->markHandled((int) $id, $admin->id ?? 0, \is_string($notiz) && trim($notiz) !== '' ? trim($notiz) : null);

        $this->session->flash('erfolg', 'Als erledigt vermerkt.');

        return Response::redirect('/admin/kontakt');
    }

    private function requireAdmin(): User
    {
        $user = $this->currentUser->require();

        if ($user->role !== Role::Admin) {
            throw HttpException::notFound('Seite nicht gefunden.');
        }

        return $user;
    }

    private function requireUser(Request $request): User
    {
        $id = $request->attribute('id');

        if ($id === null || !ctype_digit($id)) {
            throw HttpException::notFound('Konto nicht gefunden.');
        }

        return $this->users->findById((int) $id) ?? throw HttpException::notFound('Konto nicht gefunden.');
    }
}
