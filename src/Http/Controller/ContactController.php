<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Contact\ContactException;
use Reptilienmarkt\Domain\Contact\ContactService;
use Reptilienmarkt\Domain\Contact\ContactTopic;
use Reptilienmarkt\Domain\Site\SiteIdentity;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Twig\Environment;

/**
 * Kontaktformular an die Verwaltung.
 *
 * Ohne Anmeldung erreichbar — und das ist der Punkt: Ausgerechnet wer nicht
 * mehr hineinkommt, weil sein Konto gesperrt ist oder er sein Passwort nicht
 * zurueckbekommt, muss schreiben koennen.
 */
final readonly class ContactController
{
    public function __construct(
        private ContactService $contact,
        private SiteIdentity $identity,
        private RateLimiter $rateLimiter,
        private Viewer $currentUser,
        private SessionManager $session,
        private Environment $twig,
    ) {}

    public function show(Request $request): Response
    {
        return Response::html($this->twig->render('kontakt.html.twig', $this->viewData([])));
    }

    public function submit(Request $request): Response
    {
        $this->session->assertCsrf($request);
        $user = $this->currentUser->get();

        // Ein Feld, das kein Mensch sieht und kein Mensch ausfuellt. Wer es
        // trotzdem tut, bekommt dieselbe Bestaetigung wie alle — ein Bot soll
        // nicht lernen, woran er gescheitert ist.
        $falle = $request->body['website'] ?? '';

        if (\is_string($falle) && trim($falle) !== '') {
            $this->session->flash('erfolg', 'Danke, deine Nachricht ist angekommen.');

            return Response::redirect('/kontakt');
        }

        if ($request->clientIp !== null && !$this->rateLimiter->attempt('kontakt.ip', $request->clientIp)->allowed) {
            $this->session->flash('fehler', 'Von diesem Anschluss kamen gerade mehrere Nachrichten. Bitte warte etwas.');

            return Response::redirect('/kontakt');
        }

        try {
            $this->contact->submit($request->body, $user, $request->clientIp);
        } catch (ContactException $exception) {
            return Response::html($this->twig->render('kontakt.html.twig', $this->viewData(
                $request->body,
                $exception->getMessage(),
            )), 422);
        }

        $this->session->flash('erfolg', 'Danke, deine Nachricht ist angekommen. Wir melden uns per E-Mail.');

        return Response::redirect('/kontakt');
    }

    /**
     * @param array<string, mixed> $eingaben
     *
     * @return array<string, mixed>
     */
    private function viewData(array $eingaben, ?string $fehler = null): array
    {
        $user = $this->currentUser->get();

        return [
            'themen' => ContactTopic::cases(),
            'nutzer' => $user,
            'kontakt' => $this->identity->contact(),
            'anschrift' => $this->identity->addressLines(),
            // Nur die Textfelder zurueck ins Formular — was kein Text ist,
            // war auch keine Eingabe.
            'eingaben' => array_filter($eingaben, static fn(mixed $wert): bool => \is_string($wert)),
            'max_nachricht' => ContactService::MAX_BODY,
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $fehler === null ? $this->session->takeFlashes() : ['fehler' => $fehler],
        ];
    }
}
