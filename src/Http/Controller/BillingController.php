<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Billing\BillingException;
use Reptilienmarkt\Domain\Billing\BillingService;
use Reptilienmarkt\Domain\Billing\BoostService;
use Reptilienmarkt\Domain\Billing\EntitlementService;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Twig\Environment;

/**
 * Tarifuebersicht, Kauf und Rueckmeldungen des Zahlungsanbieters.
 *
 * Solange die Monetarisierung nicht aktiviert ist, zeigt die Tarifseite die
 * Preise als Vorschau und keinen einzigen Kaufknopf. Die Kaufwege sind
 * trotzdem verdrahtet und beantworten jeden Versuch mit einer klaren Meldung —
 * lieber ein sichtbares Nein als eine halb angelegte Bestellung.
 */
final readonly class BillingController
{
    public function __construct(
        private BillingService $billing,
        private EntitlementService $entitlements,
        private BoostService $boosts,
        private ListingRepository $listings,
        private UserRepository $users,
        private Viewer $currentUser,
        private SessionManager $session,
        private Environment $twig,
    ) {}

    /**
     * Oeffentliche Tarifuebersicht. Auch ohne Anmeldung sichtbar — wer wissen
     * will, was etwas kostet, soll sich dafuer nicht registrieren muessen.
     */
    public function plans(Request $request): Response
    {
        $user = $this->currentUser->get();

        return Response::html($this->twig->render('billing/tarife.html.twig', [
            'tarife' => $this->entitlements->plans(),
            'boosts' => $this->boosts->options(),
            'aktiviert' => $this->billing->enabled(),
            'kaufbar' => $this->billing->purchasable(),
            'rechte' => $user === null ? null : $this->entitlements->forUser($user),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    /**
     * Die eigene Abrechnung: laufender Tarif, Belege, Kündigung.
     */
    public function overview(Request $request): Response
    {
        $user = $this->currentUser->require();
        $rechte = $this->entitlements->forUser($user);

        return Response::html($this->twig->render('billing/konto.html.twig', [
            'rechte' => $rechte,
            'abo' => $rechte->subscription,
            'belege' => $this->billing->paymentsFor($user),
            'aktive_anzeigen' => $this->users->salesStatistics($user->id ?? 0)['aktive_anzeigen'],
            'aktiviert' => $this->billing->enabled(),
            'kaufbar' => $this->billing->purchasable(),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function subscribe(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        try {
            $session = $this->billing->startSubscriptionCheckout($user, $this->input($request, 'tarif'));
        } catch (BillingException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/tarife');
        }

        return Response::redirect($session->redirectUrl, 303);
    }

    public function cancel(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        try {
            $abo = $this->billing->cancelSubscription($user);

            $this->session->flash('erfolg', \sprintf(
                'Die Mitgliedschaft endet am %s. Bis dahin bleibt alles wie gehabt.',
                $abo->currentPeriodEnd->format('d.m.Y'),
            ));
        } catch (BillingException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect('/konto/abrechnung');
    }

    public function boost(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        $listingId = $this->requireId($request);
        $listing = $this->listings->findById($listingId);

        // 404 statt 403 — eine fremde Anzeige soll sich nicht durch eine
        // abgelehnte Buchung verraten.
        if ($listing === null || !$listing->belongsTo($user->id ?? 0)) {
            throw HttpException::notFound('Anzeige nicht gefunden.');
        }

        try {
            $session = $this->billing->startBoostCheckout($user, $listingId, $this->input($request, 'boost'));
        } catch (BillingException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect(\sprintf('/anzeige/%d/', $listingId));
        }

        return Response::redirect($session->redirectUrl, 303);
    }

    /**
     * Die Rueckmeldung des Zahlungsanbieters.
     *
     * Antwortet grundsaetzlich mit 200, sobald die Nachricht echt war —
     * andernfalls stellt der Anbieter sie endlos erneut zu. Freigeschaltet
     * wird ausschliesslich hier, nie bei der Rueckkehr des Browsers von der
     * Bezahlseite: Die laesst sich aufrufen, ohne bezahlt zu haben.
     */
    public function webhook(Request $request): Response
    {
        $verarbeitet = $this->billing->handleWebhook($request->raw(), $request->headers);

        return Response::json(['verarbeitet' => $verarbeitet], $verarbeitet ? 200 : 400);
    }

    public function paymentReturn(Request $request): Response
    {
        $this->currentUser->require();

        // Bewusst ohne Freischaltung: Diese Seite bestaetigt nichts, sie
        // beruhigt nur. Gebucht wird ueber die Rueckmeldung des Anbieters.
        $this->session->flash(
            'erfolg',
            'Danke. Sobald die Zahlung bestätigt ist, schalten wir die Leistung frei — das dauert meist nur Sekunden.',
        );

        return Response::redirect('/konto/abrechnung');
    }

    public function paymentCancelled(Request $request): Response
    {
        $this->currentUser->require();
        $this->session->flash('fehler', 'Die Zahlung wurde abgebrochen. Es wurde nichts berechnet.');

        return Response::redirect('/tarife');
    }

    private function requireId(Request $request): int
    {
        $value = $request->attribute('id');

        if ($value === null || !ctype_digit($value)) {
            throw HttpException::notFound('Nicht gefunden.');
        }

        return (int) $value;
    }

    private function input(Request $request, string $name): string
    {
        $value = $request->body[$name] ?? '';

        return \is_string($value) ? trim($value) : '';
    }

    private function guardCsrf(Request $request): void
    {
        $token = $request->body['_csrf'] ?? null;

        if (!\is_string($token) || !$this->session->verifyCsrf($token)) {
            throw HttpException::badRequest('Das Formular ist abgelaufen. Bitte lade die Seite neu.');
        }
    }
}
