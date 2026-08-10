<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Auth\SessionRepository;
use Reptilienmarkt\Domain\Auth\TokenException;
use Reptilienmarkt\Domain\Auth\TokenType;
use Reptilienmarkt\Domain\Auth\TotpAuthenticator;
use Reptilienmarkt\Domain\Notification\NotificationChannel;
use Reptilienmarkt\Domain\Notification\NotificationException;
use Reptilienmarkt\Domain\Notification\NotificationPreferenceService;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Domain\Trust\RateLimitExceededException;
use Reptilienmarkt\Domain\User\AccountException;
use Reptilienmarkt\Domain\User\AccountService;
use Reptilienmarkt\Domain\User\UserDocument;
use Reptilienmarkt\Domain\User\UserDocumentRepository;
use Reptilienmarkt\Domain\User\UserDocumentType;
use Reptilienmarkt\Http\HttpException;
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
        private NotificationPreferenceService $notifications,
        private SessionRepository $sessions,
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
                // Die eigene Sitzung bleibt — wer sein Passwort wechselt, soll
                // sich nicht dabei selbst aussperren.
                $this->session->id(),
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

    // ------------------------------------------------------ Sitzungen

    public function sessions(Request $request): Response
    {
        $user = $this->currentUser->require();

        return Response::html($this->twig->render('konto/sitzungen.html.twig', [
            'sitzungen' => $this->sessions->forUser($user->id ?? 0),
            'aktuelle' => $this->session->id(),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function endSession(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        $id = $request->attribute('id') ?? '';
        $sitzung = $id === '' ? null : $this->sessions->find($id);

        // 404 statt 403: Eine fremde Sitzungskennung soll nicht einmal in ihrer
        // Existenz bestaetigt werden.
        if ($sitzung === null || $sitzung->userId !== ($user->id ?? 0)) {
            throw HttpException::notFound('Sitzung nicht gefunden.');
        }

        if ($sitzung->id === $this->session->id()) {
            // Die eigene Sitzung hier zu beenden waere ein Abmelden mit
            // falschem Namen — dafuer gibt es den Abmeldeknopf.
            $this->session->flash('fehler', $this->translator->translate('sitzungen.eigene_nicht'));

            return Response::redirect('/konto/sitzungen');
        }

        $this->sessions->delete($sitzung->id);
        $this->session->flash('erfolg', $this->translator->translate('sitzungen.beendet'));

        return Response::redirect('/konto/sitzungen');
    }

    /**
     * Alle uebrigen Sitzungen beenden — gegen Passwort.
     *
     * Das Passwort ist hier kein Formalismus: Genau diese Massnahme greift
     * gegen eine uebernommene Sitzung, und wer die Sitzung uebernommen hat,
     * soll sie nicht gegen den rechtmaessigen Inhaber richten koennen.
     */
    public function endAllSessions(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        if (!$this->accounts->verifyPassword($user, $this->input($request, 'passwort'))) {
            $this->session->flash('fehler', $this->translator->translate('sitzungen.passwort_falsch'));

            return Response::redirect('/konto/sitzungen');
        }

        $beendet = $this->accounts->endAllSessions($user, $this->session->id());

        $this->session->flash('erfolg', $this->translator->translate('sitzungen.alle_beendet', ['anzahl' => $beendet]));

        return Response::redirect('/konto/sitzungen');
    }

    // ------------------------------------------------- Benachrichtigungen

    public function notifications(Request $request): Response
    {
        $user = $this->currentUser->require();

        return Response::html($this->twig->render('konto/benachrichtigungen.html.twig', [
            'waehlbare_kanaele' => NotificationChannel::selectable(),
            'pflicht_kanal' => NotificationChannel::SystemWichtig,
            'zustand' => $this->notifications->all($user->id ?? 0),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function saveNotifications(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        $this->notifications->save($user->id ?? 0, $this->checkedChannels($request));
        $this->session->flash('erfolg', $this->translator->translate('benachrichtigung.gespeichert'));

        return Response::redirect('/konto/benachrichtigungen');
    }

    /**
     * Macht alle Abmeldelinks des Kontos ungueltig.
     *
     * Der Abmeldelink gilt seit Phase 12 dauerhaft — er wird aus einem
     * konto-eigenen Geheimnis abgeleitet, statt bei jedem Versand neu
     * ausgestellt zu werden. Damit braucht es einen bewussten Weg, ihn zu
     * entwerten: fuer den Fall, dass eine alte Mail in fremde Haende geraten
     * ist. Hier, nicht bei jedem Versand — sonst waeren die Links wieder nach
     * einer Mail tot.
     */
    public function resetUnsubscribeLinks(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        $this->notifications->rotateUnsubscribeSecret($user->id ?? 0);
        $this->session->flash('erfolg', $this->translator->translate('benachrichtigung.abmeldelinks.erneuert'));

        return Response::redirect('/konto/benachrichtigungen');
    }

    /**
     * Der Abmeldelink aus einer Mail — die Seite dazu, nicht die Wirkung.
     *
     * Sie aendert nichts. Ein GET auf einen Link in einer Mail ist keine
     * Entscheidung des Nutzers: Outlook Safe Links, Gmail-Prefetch und die
     * URL-Sandboxes von Firmenfiltern rufen ihn ungefragt auf, teils bevor die
     * Mail ueberhaupt jemand gesehen hat. Was hier steht, ist deshalb eine
     * Frage mit einem Knopf, und der Knopf schickt einen POST.
     *
     * Ohne Anmeldung, wie der Bestaetigungslink: Wer die Mail hat, hat den
     * Nachweis erbracht. Und ohne jede Wirkung auf die Sitzung — dieser Weg
     * betrifft genau einen Kanal und meldet niemanden von irgendetwas anderem
     * ab, obwohl der Pfad so heisst.
     */
    public function confirmUnsubscribe(Request $request): Response
    {
        $kanal = NotificationChannel::tryFrom($request->queryString('kanal') ?? '');
        $token = $request->attribute('token') ?? '';

        if ($kanal === null) {
            return $this->unsubscribeResult(null, $this->translator->translate('benachrichtigung.abmelden.unbekannt'));
        }

        // Geprueft wird hier nur, geaendert nichts — und mit demselben Wortlaut
        // wie beim POST, damit ein erfundener Token nicht daran zu erkennen
        // ist, dass die Seite anders antwortet als die Abmeldung.
        try {
            $this->notifications->requireAccountForToken($token, $kanal);
        } catch (NotificationException $exception) {
            return $this->unsubscribeResult(null, $exception->getMessage());
        }

        return Response::html($this->twig->render('konto/abmelden.html.twig', [
            'kanal' => $kanal,
            'ziel' => \sprintf('/abmelden/%s?kanal=%s', rawurlencode($token), rawurlencode($kanal->value)),
        ]));
    }

    /**
     * Fuehrt die Abmeldung aus.
     *
     * **Ohne CSRF-Token, und das mit Absicht.** Ein CSRF-Token schuetzt eine
     * Sitzung davor, dass eine fremde Seite in ihrem Namen handelt. Hier gibt
     * es keine Sitzung: Der Nachweis ist der Token im Pfad, den nur kennt, wer
     * die Mail hat. Ein zusaetzlicher CSRF-Token wuerde daran nichts sichern —
     * er wuerde nur den Ein-Klick-POST nach RFC 8058 unmoeglich machen, denn
     * der kommt vom Mailanbieter (Gmail, Outlook) und nicht aus einem Browser
     * mit unserer Sitzung. Deshalb darf hier nichts erwartet werden ausser dem
     * Pfad: kein Formularfeld, kein Cookie, kein Referer. Der Rumpf des
     * Ein-Klick-POST ist "List-Unsubscribe=One-Click" — gelesen wird er nicht.
     */
    public function unsubscribe(Request $request): Response
    {
        $kanal = NotificationChannel::tryFrom($request->queryString('kanal') ?? '');

        if ($kanal === null) {
            return $this->unsubscribeResult(null, $this->translator->translate('benachrichtigung.abmelden.unbekannt'));
        }

        try {
            $this->notifications->unsubscribe($request->attribute('token') ?? '', $kanal);
        } catch (NotificationException $exception) {
            return $this->unsubscribeResult(null, $exception->getMessage());
        }

        return $this->unsubscribeResult($kanal, null);
    }

    private function unsubscribeResult(?NotificationChannel $channel, ?string $error): Response
    {
        return Response::html(
            $this->twig->render('konto/abgemeldet.html.twig', [
                'erfolg' => $channel !== null,
                'kanal' => $channel,
                'fehler' => $error,
            ]),
            $channel === null ? 404 : 200,
        );
    }

    /**
     * Die angehakten Kaestchen. Ein Browser schickt nicht angehakte Kaestchen
     * gar nicht mit — was fehlt, ist also abgewaehlt und nicht unveraendert.
     *
     * @return list<string>
     */
    private function checkedChannels(Request $request): array
    {
        $roh = $request->body['kanaele'] ?? [];

        if (!\is_array($roh)) {
            return [];
        }

        $schluessel = [];

        foreach ($roh as $wert) {
            if (\is_string($wert) && NotificationChannel::tryFrom($wert) !== null) {
                $schluessel[] = $wert;
            }
        }

        return $schluessel;
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
