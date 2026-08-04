<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Auth\AuthenticationException;
use Reptilienmarkt\Domain\Auth\AuthenticationService;
use Reptilienmarkt\Domain\Auth\RegistrationException;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Twig\Environment;

final readonly class AuthController
{
    public function __construct(
        private AuthenticationService $auth,
        private RateLimiter $rateLimiter,
        private SessionManager $session,
        private Viewer $currentUser,
        private AuditLog $audit,
        private Environment $twig,
    ) {}

    public function showRegister(Request $request): Response
    {
        if ($this->currentUser->isAuthenticated()) {
            return Response::redirect('/meine-anzeigen/');
        }

        return Response::html($this->twig->render('auth/registrieren.html.twig', [
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
            'eingaben' => [],
        ]));
    }

    public function register(Request $request): Response
    {
        $this->guardCsrf($request);

        $email = $this->input($request, 'email');
        $name = $this->input($request, 'anzeigename');
        $password = $this->input($request, 'passwort');

        try {
            $user = $this->auth->register($email, $name, $password);
        } catch (RegistrationException $exception) {
            return Response::html($this->twig->render('auth/registrieren.html.twig', [
                'csrf' => $this->session->csrfToken(),
                'meldungen' => ['fehler' => $exception->getMessage()],
                'eingaben' => ['email' => $email, 'anzeigename' => $name],
            ]), 422);
        }

        $this->session->login($user->id ?? 0);
        $this->audit->record(new AuditEntry('user.registered', 'user', $user->id, [], $user->id, ipAddress: $request->clientIp));
        $this->session->flash('erfolg', 'Willkommen! Dein Konto ist angelegt.');

        return Response::redirect('/meine-anzeigen/');
    }

    public function showLogin(Request $request): Response
    {
        if ($this->currentUser->isAuthenticated()) {
            return Response::redirect('/meine-anzeigen/');
        }

        return Response::html($this->twig->render('auth/anmelden.html.twig', [
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
            'weiter' => $request->queryString('weiter'),
            'eingaben' => [],
        ]));
    }

    public function login(Request $request): Response
    {
        $this->guardCsrf($request);

        $email = $this->input($request, 'email');
        $weiter = $this->input($request, 'weiter');

        // Die Kontosperre nach fuenf Fehlversuchen schuetzt ein Konto. Sie
        // schuetzt nicht davor, dass jemand dieselbe Handvoll Passwoerter
        // gegen tausend Konten laufen laesst — dafuer ist diese Grenze da.
        if ($request->clientIp !== null && !$this->rateLimiter->attempt('anmeldung.ip', $request->clientIp)->allowed) {
            return Response::html($this->twig->render('auth/anmelden.html.twig', [
                'csrf' => $this->session->csrfToken(),
                'meldungen' => ['fehler' => 'Zu viele Anmeldeversuche. Bitte warte einen Moment.'],
                'weiter' => $weiter === '' ? null : $weiter,
                'eingaben' => ['email' => $email],
            ]), 429);
        }

        try {
            $user = $this->auth->authenticate($email, $this->input($request, 'passwort'));
        } catch (AuthenticationException $exception) {
            $this->audit->record(new AuditEntry(
                'user.login_failed',
                'user',
                null,
                ['email' => $email],
                null,
                \Reptilienmarkt\Domain\Audit\AuditActorType::System,
                $request->clientIp,
            ));

            return Response::html($this->twig->render('auth/anmelden.html.twig', [
                'csrf' => $this->session->csrfToken(),
                'meldungen' => ['fehler' => $exception->getMessage()],
                'weiter' => $weiter === '' ? null : $weiter,
                'eingaben' => ['email' => $email],
            ]), 401);
        }

        $this->session->login($user->id ?? 0);
        $this->audit->record(new AuditEntry('user.logged_in', 'user', $user->id, [], $user->id, ipAddress: $request->clientIp));

        // Nur eigene Pfade als Rueckziel akzeptieren, nie eine fremde Adresse.
        $target = str_starts_with($weiter, '/') && !str_starts_with($weiter, '//')
            ? $weiter
            : '/meine-anzeigen/';

        return Response::redirect($target);
    }

    public function logout(Request $request): Response
    {
        $this->guardCsrf($request);

        $user = $this->currentUser->get();
        if ($user !== null) {
            $this->audit->record(new AuditEntry('user.logged_out', 'user', $user->id, [], $user->id, ipAddress: $request->clientIp));
        }

        $this->session->logout();
        $this->session->flash('erfolg', 'Du bist abgemeldet.');

        return Response::redirect('/markt/');
    }

    private function guardCsrf(Request $request): void
    {
        $this->session->assertCsrf($request);
    }

    private function input(Request $request, string $name): string
    {
        $value = $request->body[$name] ?? '';

        return \is_string($value) ? trim($value) : '';
    }
}
