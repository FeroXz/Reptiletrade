<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Auth\TokenException;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Domain\User\AccountException;
use Reptilienmarkt\Domain\User\AccountService;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Twig\Environment;

/**
 * Passwort vergessen.
 *
 * Die Antwort ist immer dieselbe, egal ob es die Adresse gibt: Sonst liesse
 * sich das Formular zum Durchprobieren von Adressen verwenden.
 */
final readonly class PasswordResetController
{
    public function __construct(
        private AccountService $accounts,
        private RateLimiter $rateLimiter,
        private SessionManager $session,
        private Environment $twig,
    ) {}

    public function showRequest(Request $request): Response
    {
        return Response::html($this->twig->render('auth/passwort_vergessen.html.twig', [
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function sendLink(Request $request): Response
    {
        $this->guardCsrf($request);

        $bestaetigung = 'Wenn es zu dieser Adresse ein Konto gibt, ist die E-Mail unterwegs.';

        if ($request->clientIp !== null) {
            $decision = $this->rateLimiter->attempt('passwort_reset.ip', $request->clientIp);

            // Auch die Abweisung bekommt dieselbe Meldung — die Rate-Grenze
            // soll nicht verraten, welche Adressen es gibt.
            if (!$decision->allowed) {
                $this->session->flash('erfolg', $bestaetigung);

                return Response::redirect('/passwort/vergessen');
            }
        }

        $this->accounts->requestPasswordReset($this->input($request, 'email'));
        $this->session->flash('erfolg', $bestaetigung);

        return Response::redirect('/passwort/vergessen');
    }

    public function showReset(Request $request): Response
    {
        return Response::html($this->twig->render('auth/passwort_neu.html.twig', [
            'token' => $request->queryString('token') ?? '',
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function reset(Request $request): Response
    {
        $this->guardCsrf($request);

        $token = $this->input($request, 'token');

        try {
            $this->accounts->resetPassword($token, $this->input($request, 'passwort'));
        } catch (AccountException|TokenException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/passwort/neu?token=' . rawurlencode($token));
        }

        $this->session->flash('erfolg', 'Dein Passwort ist gesetzt. Du kannst dich jetzt anmelden.');

        return Response::redirect('/anmelden');
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
