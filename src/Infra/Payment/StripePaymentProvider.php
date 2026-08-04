<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Payment;

use Reptilienmarkt\Domain\Billing\BillingException;
use Reptilienmarkt\Domain\Billing\CheckoutRequest;
use Reptilienmarkt\Domain\Billing\CheckoutSession;
use Reptilienmarkt\Domain\Billing\Money;
use Reptilienmarkt\Domain\Billing\PaymentEvent;
use Reptilienmarkt\Domain\Billing\PaymentProvider;
use Reptilienmarkt\Domain\Billing\PaymentStatus;

/**
 * Stripe als Referenzumsetzung.
 *
 * Bewusst ohne das offizielle SDK: Fuer Checkout-Sitzung, Webhook-Pruefung und
 * Abo-Kuendigung sind es drei HTTP-Aufrufe, und ein weiteres Paket im
 * Zahlungsweg will gepflegt sein. Wer das SDK bevorzugt, tauscht diese Klasse
 * aus — dafuer gibt es das Interface.
 *
 * Diese Umsetzung ist nicht aktiviert. Sie zeigt, was ein Anbieter erfuellen
 * muss, und ist gegen die dokumentierte Schnittstelle geschrieben; vor dem
 * ersten echten Einsatz gehoert sie gegen Stripes Testmodus geprueft.
 */
final readonly class StripePaymentProvider implements PaymentProvider
{
    /**
     * Toleranz fuer den Zeitstempel der Webhook-Signatur. Fuenf Minuten sind
     * Stripes Empfehlung: genug fuer ungenaue Serveruhren, zu wenig fuer das
     * Wiedereinspielen einer alten Nachricht.
     */
    private const int SIGNATURE_TOLERANCE_SECONDS = 300;

    public function __construct(
        private string $secretKey,
        private string $webhookSecret,
        private string $apiBase = 'https://api.stripe.com/v1',
        private ?HttpClient $http = null,
    ) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function isOperational(): bool
    {
        return $this->secretKey !== '' && $this->webhookSecret !== '';
    }

    public function createCheckout(CheckoutRequest $request, string $successUrl, string $cancelUrl): CheckoutSession
    {
        if (!$this->isOperational()) {
            throw new BillingException('Für Stripe fehlen Schlüssel oder Webhook-Geheimnis.');
        }

        $parameters = [
            'mode' => $request->isRecurring() ? 'subscription' : 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) ($request->user->id ?? 0),
            'customer_email' => $request->user->email,
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => strtolower($request->amount->currency),
            'line_items[0][price_data][unit_amount]' => (string) $request->amount->cents,
            'line_items[0][price_data][product_data][name]' => $request->description,
            // Der Verwendungszweck kommt im Webhook zurueck — sonst waere dort
            // nicht erkennbar, wofuer bezahlt wurde.
            'metadata[purpose]' => $request->purpose->value,
            'metadata[item_key]' => $request->itemKey,
            'metadata[reference_id]' => (string) ($request->referenceId ?? 0),
        ];

        if ($request->isRecurring() && $request->interval !== null) {
            $parameters['line_items[0][price_data][recurring][interval]'] =
                $request->interval->value === 'jaehrlich' ? 'year' : 'month';
        }

        $response = $this->post('/checkout/sessions', $parameters);

        $url = $response['url'] ?? null;
        $id = $response['id'] ?? null;

        if (!\is_string($url) || !\is_string($id)) {
            throw new BillingException('Stripe hat keine verwertbare Bezahlseite geliefert.');
        }

        return new CheckoutSession($url, $id, $this->name());
    }

    public function parseWebhook(string $payload, array $headers): ?PaymentEvent
    {
        $signature = $headers['stripe-signature'] ?? null;

        // Ohne gueltige Signatur wird gar nichts ausgewertet: Der Endpunkt ist
        // oeffentlich erreichbar, und eine gefaelschte Zahlungsbestaetigung
        // waere Ware gegen nichts.
        if (!\is_string($signature) || !$this->signatureIsValid($payload, $signature)) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($payload, true);
        if (!\is_array($decoded)) {
            return null;
        }

        $type = $decoded['type'] ?? null;
        $object = $decoded['data']['object'] ?? null;

        if (!\is_string($type) || !\is_array($object)) {
            return null;
        }

        $status = match ($type) {
            'checkout.session.completed', 'invoice.paid' => PaymentStatus::Bezahlt,
            'checkout.session.expired', 'invoice.payment_failed' => PaymentStatus::Fehlgeschlagen,
            'charge.refunded' => PaymentStatus::Erstattet,
            default => null,
        };

        if ($status === null) {
            return null;
        }

        $reference = $object['id'] ?? null;
        if (!\is_string($reference)) {
            return null;
        }

        $amount = $object['amount_total'] ?? $object['amount_paid'] ?? null;
        $currency = $object['currency'] ?? 'eur';

        return new PaymentEvent(
            $reference,
            $status,
            \is_int($amount) && \is_string($currency) ? new Money($amount, strtoupper($currency)) : null,
            \is_string($object['customer'] ?? null) ? $object['customer'] : null,
            \is_string($object['subscription'] ?? null) ? $object['subscription'] : null,
        );
    }

    public function cancelSubscription(string $providerReference): bool
    {
        if (!$this->isOperational()) {
            return false;
        }

        // Zum Periodenende, nicht sofort — bezahlt ist bezahlt.
        $response = $this->post('/subscriptions/' . rawurlencode($providerReference), [
            'cancel_at_period_end' => 'true',
        ]);

        return ($response['cancel_at_period_end'] ?? false) === true;
    }

    /**
     * Signaturpruefung nach Stripes Schema: t=Zeitstempel,v1=HMAC-SHA256 ueber
     * "Zeitstempel.Nutzlast".
     */
    private function signatureIsValid(string $payload, string $header): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (\count($pair) !== 2) {
                continue;
            }

            if ($pair[0] === 't') {
                $timestamp = $pair[1];
            } elseif ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }

        if ($timestamp === null || !ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        // Alte Nachrichten abweisen, sonst laesst sich eine einmal
        // mitgeschnittene Bestaetigung beliebig oft erneut einspielen.
        if (abs(time() - (int) $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false;
        }

        // Ohne hinterlegtes Geheimnis wird nichts geprueft, sondern abgewiesen.
        // hash_hmac mit leerem Schluessel rechnet klaglos weiter — und dann
        // koennte jeder die Signatur selbst ausrechnen und sich eine bezahlte
        // Rechnung schicken. Ein fehlender Schluessel ist keine Konfiguration,
        // sondern ein geschlossener Endpunkt.
        if ($this->webhookSecret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $this->webhookSecret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $parameters): array
    {
        $client = $this->http ?? new CurlHttpClient();

        $response = $client->post(
            $this->apiBase . $path,
            http_build_query($parameters),
            [
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        );

        /** @var mixed $decoded */
        $decoded = json_decode($response, true);

        if (!\is_array($decoded)) {
            throw new BillingException('Stripe hat keine verwertbare Antwort geliefert.');
        }

        if (isset($decoded['error'])) {
            $message = \is_array($decoded['error']) && \is_string($decoded['error']['message'] ?? null)
                ? $decoded['error']['message']
                : 'Unbekannter Fehler';

            throw new BillingException('Stripe: ' . $message);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
