<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Auth\PasswordHasher;
use Reptilienmarkt\Domain\Privacy\AccountDeletionService;
use Reptilienmarkt\Domain\Privacy\DataExportService;
use Reptilienmarkt\Domain\Privacy\PrivacyException;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Twig\Environment;

/**
 * Datenauskunft und Kontoloeschung.
 *
 * Beides sind Rechte des Nutzers, keine Gefaelligkeiten — deshalb ohne
 * Ruecksprache, ohne Begruendungspflicht und ohne Wartezeit. Die Loeschung
 * verlangt allerdings das Passwort: Eine uebernommene Sitzung soll kein Konto
 * ausloeschen koennen.
 */
final readonly class PrivacyController
{
    /** @var list<string> */
    private const array CONFIRMATION_WORDS = ['LÖSCHEN', 'LOESCHEN'];

    public function __construct(
        private DataExportService $export,
        private AccountDeletionService $deletion,
        private UserRepository $users,
        private PasswordHasher $hasher,
        private Viewer $currentUser,
        private SessionManager $session,
        private Environment $twig,
    ) {}

    public function show(Request $request): Response
    {
        $user = $this->currentUser->require();

        return Response::html($this->twig->render('konto/daten.html.twig', [
            'nutzer' => $user,
            'vorschau' => $this->deletion->preview($user),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    /**
     * Auskunft nach Artikel 15 DSGVO als JSON-Download.
     */
    public function download(Request $request): Response
    {
        $user = $this->currentUser->require();

        return new Response(
            $this->export->toJson($user),
            200,
            [
                'content-type' => 'application/json; charset=utf-8',
                'content-disposition' => 'attachment; filename="' . $this->export->filename($user) . '"',
                // Eine Datenauskunft gehoert weder in einen Zwischenspeicher
                // noch in einen Suchindex.
                'cache-control' => 'private, no-store',
                'x-robots-tag' => 'noindex, nofollow',
            ],
        );
    }

    public function delete(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        $passwort = $request->body['passwort'] ?? '';
        $hash = $this->users->passwordHashFor($user->id ?? 0);

        if (!\is_string($passwort) || $hash === null || !$this->hasher->verify($passwort, $hash)) {
            $this->session->flash('fehler', 'Das Passwort stimmt nicht.');

            return Response::redirect('/konto/daten');
        }

        // Ohne diese Bestaetigung waere ein Fehlklick unwiderruflich. Wer das
        // Wort ohne Umlaut tippt, meint dasselbe — daran soll es nicht scheitern.
        $bestaetigung = $request->body['bestaetigung'] ?? '';
        $bestaetigung = \is_string($bestaetigung) ? mb_strtoupper(trim($bestaetigung)) : '';

        if (!\in_array($bestaetigung, self::CONFIRMATION_WORDS, true)) {
            $this->session->flash('fehler', 'Bitte tippe LÖSCHEN in das Bestätigungsfeld.');

            return Response::redirect('/konto/daten');
        }

        try {
            $ergebnis = $this->deletion->delete($user, $user->id);
        } catch (PrivacyException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/konto/daten');
        }

        $this->session->logout();
        $this->session->flash('erfolg', $ergebnis->message());

        return Response::redirect('/markt/');
    }

    private function guardCsrf(Request $request): void
    {
        $this->session->assertCsrf($request);
    }
}
