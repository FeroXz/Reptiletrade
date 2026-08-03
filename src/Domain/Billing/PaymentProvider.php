<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

/**
 * Der Zahlungsanbieter.
 *
 * Bewusst schmal: Bezahlseite eroeffnen, Rueckmeldung entgegennehmen, Abo
 * beenden. Alles darueber hinaus — Rechnungen, Mahnwesen, Steuersaetze je
 * Land — bleibt beim Anbieter, denn genau dafuer bezahlt man ihn.
 *
 * Kein Treuhandservice: Die Plattform nimmt kein Geld fuer Tierverkaeufe
 * entgegen, sondern ausschliesslich fuer eigene Leistungen. Damit gibt es
 * keine Zahlung zwischen Nutzern, die abgesichert werden muesste — und keine
 * der Pflichten, die daran haengen.
 */
interface PaymentProvider
{
    public function name(): string;

    /**
     * Kann ueberhaupt bezahlt werden? Der Null-Anbieter antwortet mit false,
     * und die Oberflaeche zeigt dann gar keine Kaufknoepfe an.
     */
    public function isOperational(): bool;

    /**
     * Eroeffnet eine Bezahlung.
     *
     * @throws BillingException wenn der Anbieter nicht bereit ist
     */
    public function createCheckout(CheckoutRequest $request, string $successUrl, string $cancelUrl): CheckoutSession;

    /**
     * Uebersetzt eine eingehende Rueckmeldung. Liefert null, wenn die Nachricht
     * nicht zu dieser Anwendung gehoert oder ihre Echtheit nicht feststeht.
     *
     * @param array<string, string> $headers
     */
    public function parseWebhook(string $payload, array $headers): ?PaymentEvent;

    /**
     * Beendet ein laufendes Abo beim Anbieter. Zum Periodenende, nicht sofort:
     * Bezahlt ist bezahlt.
     */
    public function cancelSubscription(string $providerReference): bool;
}
