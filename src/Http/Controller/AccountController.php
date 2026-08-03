<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Auth\TokenException;
use Reptilienmarkt\Domain\Auth\TokenType;
use Reptilienmarkt\Domain\Auth\TotpAuthenticator;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Domain\Trust\RateLimitExceededException;
use Reptilienmarkt\Domain\User\AccountException;
use Reptilienmarkt\Domain\User\AccountService;
use Reptilienmarkt\Domain\User\UserDocument;
use Reptilienmarkt\Domain\User\UserDocumentRepository;
use Reptilienmarkt\Domain\User\UserDocumentType;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Infra\Storage\PrivateStorage;
use Reptilienmarkt\Infra\Storage\StorageException;
use Reptilienmarkt\Support\Translator;
use Twig\Environment;

/**
 * Kontoverwaltung: Verifizierungsstufen, Passwort, Zwei-Faktor, Nachweise.
 */
final readonly class AccountController
{
    public function __construct(
        private AccountService $accounts,
        private UserDocumentRepository $documents,
        private PrivateStorage $storage,
        private TotpAuthenticator $totp,
        private RateLimiter $rateLimiter,
        private Viewer $currentUser,
        private SessionManager $session,
        private Translator $translator,
        private Environment $twig,
        private string $appName = 'Reptilienmarkt',
    ) {}

    public function show(Request $request): Response
    {
        $user = $this->currentUser->require();

        return Response::html($this->twig->render('konto/uebersicht.html.twig', [
            'nutzer' => $user,
            'stufe' => $user->verificationLevel(),
            'zwei_faktor' => $this->accounts->twoFactorEnabled($user->id ?? 0),
            'nachweise' => $this->documents->forUser($user->id ?? 0),
            'nachweis_arten' => UserDocumentType::cases(),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    // --------------------------------------------------------- Verifizierung

    public function sendEmailVerification(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        if ($user->hasVerifiedEmail()) {
            $this->session->flash('erfolg', $this->translator->translate('konto.email_bestaetigt'));

            return Response::redirect('/konto/');
        }

        try {
            $this->limit('verifizierung.konto', (string) ($user->id ?? 0));
        } catch (RateLimitExceededException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/konto/');
        }

        $this->accounts->sendEmailVerification($user);
        $this->session->flash('erfolg', $this->translator->translate('konto.email_verify_gesendet'));

        return Response::redirect('/konto/');
    }

    /**
     * Der Link aus der Mail. Bewusst ohne Anmeldepflicht: Wer die Mail hat,
     * hat den Nachweis erbracht, und ein Zwang zur Anmeldung wuerde nur den
     * Bestaetigungsweg verlaengern.
     */
    public function confirmEmail(Request $request): Response
    {
        $token = $request->queryString('token') ?? '';

        try {
            $this->accounts->confirmEmail($token);
            $this->session->flash('erfolg', $this->translator->translate('konto.email_bestaetigt'));
        } catch (TokenException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect($this->currentUser->isAuthenticated() ? '/konto/' : '/anmelden');
    }

    public function startPhoneVerification(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        try {
            $this->limit('verifizierung.konto', (string) ($user->id ?? 0));

            $this->accounts->startPhoneVerification($user, $this->input($request, 'telefon'));

            $this->session->flash('erfolg', $this->translator->translate('konto.telefon_code_gesendet', [
                'minuten' => TokenType::PhoneVerify->lifetimeMinutes(),
            ]));
        } catch (AccountException|RateLimitExceededException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect('/konto/');
    }

    public function confirmPhone(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        try {
            $this->accounts->confirmPhone($user, $this->input($request, 'code'));
            $this->session->flash('erfolg', $this->translator->translate('konto.telefon_bestaetigt'));
        } catch (AccountException|TokenException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect('/konto/');
    }

    public function uploadDocument(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        $type = UserDocumentType::tryFrom($this->input($request, 'art'));
        $file = $request->file('datei');

        if ($type === null) {
            $this->session->flash('fehler', 'Unbekannte Nachweisart.');

            return Response::redirect('/konto/');
        }

        if ($file === null || !$file->isOk()) {
            $this->session->flash('fehler', $file?->errorMessage() ?? 'Es wurde keine Datei ausgewählt.');

            return Response::redirect('/konto/');
        }

        try {
            $stored = $this->storage->store($file->temporaryPath, $file->clientFilename, 'konto-' . ($user->id ?? 0));
        } catch (StorageException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/konto/');
        }

        $this->documents->save(new UserDocument(
            null,
            $user->id ?? 0,
            $type,
            $stored->relativePath,
            $stored->originalFilename,
            $stored->mimeType,
            $stored->byteSize,
        ));

        $this->session->flash('erfolg', $this->translator->translate('konto.nachweis_hochgeladen'));

        return Response::redirect('/konto/');
    }

    // -------------------------------------------------------------- Passwort

    public function changePassword(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        try {
            $this->accounts->changePassword(
                $user,
                $this->input($request, 'aktuelles_passwort'),
                $this->input($request, 'neues_passwort'),
            );

            $this->session->flash('erfolg', $this->translator->translate('konto.passwort_geaendert'));
        } catch (AccountException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect('/konto/');
    }

    // ------------------------------------------------------------ Zwei-Faktor

    public function setupTwoFactor(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        $secret = $this->accounts->beginTwoFactorSetup($user);

        return Response::html($this->twig->render('konto/zwei_faktor.html.twig', [
            'geheimnis' => $secret,
            'uri' => $this->totp->provisioningUri($secret, $user->email, $this->appName),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function confirmTwoFactor(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        try {
            $this->accounts->confirmTwoFactor($user, $this->input($request, 'code'));
            $this->session->flash('erfolg', $this->translator->translate('konto.zwei_faktor_aktiv'));
        } catch (AccountException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect('/konto/');
    }

    public function disableTwoFactor(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        try {
            $this->accounts->disableTwoFactor($user, $this->input($request, 'passwort'));
            $this->session->flash('erfolg', $this->translator->translate('konto.zwei_faktor_aus'));
        } catch (AccountException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect('/konto/');
    }

    /**
     * @throws RateLimitExceededException
     */
    private function limit(string $name, string $identifier): void
    {
        $decision = $this->rateLimiter->attempt($name, $identifier);

        if (!$decision->allowed) {
            throw new RateLimitExceededException($decision);
        }
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
