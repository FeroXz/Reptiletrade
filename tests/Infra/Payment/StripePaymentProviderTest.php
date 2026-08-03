<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Payment;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Billing\BillingException;
use Reptilienmarkt\Domain\Billing\CheckoutRequest;
use Reptilienmarkt\Domain\Billing\Money;
use Reptilienmarkt\Domain\Billing\PaymentPurpose;
use Reptilienmarkt\Domain\Billing\PaymentStatus;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Infra\Payment\StripePaymentProvider;
use Reptilienmarkt\Tests\Support\RecordingHttpClient;

/**
 * Die Referenzumsetzung — gegen einen aufgezeichneten HTTP-Zugang, nie gegen
 * Stripe selbst. Der Schwerpunkt liegt auf der Signaturpruefung: Der
 * Webhook-Endpunkt ist oeffentlich erreichbar, und eine gefaelschte
 * Zahlungsbestaetigung waere Ware gegen nichts.
 */
#[CoversClass(StripePaymentProvider::class)]
final class StripePaymentProviderTest extends TestCase
{
    private const string SECRET = 'sk_test_geheim';

    private const string WEBHOOK_SECRET = 'whsec_geheim';

    private function provider(?RecordingHttpClient $http = null): StripePaymentProvider
    {
        return new StripePaymentProvider(
            self::SECRET,
            self::WEBHOOK_SECRET,
            'https://api.stripe.test/v1',
            $http ?? new RecordingHttpClient(),
        );
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array{string, array<string, string>}
     */
    private function signedEvent(string $type, array $object, ?int $timestamp = null, string $secret = self::WEBHOOK_SECRET): array
    {
        $payload = json_encode(['type' => $type, 'data' => ['object' => $object]], \JSON_THROW_ON_ERROR);
        $stamp = $timestamp ?? time();
        $signature = hash_hmac('sha256', $stamp . '.' . $payload, $secret);

        return [$payload, ['stripe-signature' => \sprintf('t=%d,v1=%s', $stamp, $signature)]];
    }

    // ------------------------------------------------------- Einsatzbereit

    public function testOhneSchluesselNichtEinsatzbereit(): void
    {
        self::assertFalse((new StripePaymentProvider('', '', 'https://api.stripe.test/v1'))->isOperational());
        self::assertFalse((new StripePaymentProvider('sk_test', '', 'https://api.stripe.test/v1'))->isOperational());
        self::assertTrue($this->provider()->isOperational());
    }

    public function testOhneSchluesselKeinCheckout(): void
    {
        $provider = new StripePaymentProvider('', '', 'https://api.stripe.test/v1');

        $this->expectException(BillingException::class);

        $provider->createCheckout($this->request(), 'https://markt.example/ok', 'https://markt.example/ab');
    }

    // ------------------------------------------------------------ Checkout

    public function testCheckoutSchicktDieErwartetenFelder(): void
    {
        $http = new RecordingHttpClient(['{"id":"cs_test_1","url":"https://checkout.stripe.test/cs_test_1"}']);
        $session = $this->provider($http)->createCheckout(
            $this->request(),
            'https://markt.example/ok',
            'https://markt.example/ab',
        );

        self::assertSame('cs_test_1', $session->providerReference);
        self::assertSame('https://checkout.stripe.test/cs_test_1', $session->redirectUrl);
        self::assertSame('stripe', $session->provider);

        $letzte = $http->lastCall();
        self::assertSame('https://api.stripe.test/v1/checkout/sessions', $letzte['url']);
        self::assertSame('Bearer ' . self::SECRET, $letzte['headers']['Authorization'] ?? null);

        parse_str($letzte['body'], $felder);
        self::assertSame('payment', $felder['mode'] ?? null);
        self::assertSame('990', $felder['line_items'][0]['price_data']['unit_amount'] ?? null);
        self::assertSame('eur', $felder['line_items'][0]['price_data']['currency'] ?? null);
        // Der Verwendungszweck muss zurueckkommen, sonst ist im Webhook nicht
        // erkennbar, wofuer bezahlt wurde.
        self::assertSame('abo', $felder['metadata']['purpose'] ?? null);
    }

    public function testWiederkehrendeZahlungWirdAlsAboAngelegt(): void
    {
        $http = new RecordingHttpClient(['{"id":"cs_2","url":"https://checkout.stripe.test/cs_2"}']);

        $this->provider($http)->createCheckout(
            $this->request(\Reptilienmarkt\Domain\Billing\BillingInterval::Monatlich),
            'https://markt.example/ok',
            'https://markt.example/ab',
        );

        parse_str($http->lastCall()['body'], $felder);

        self::assertSame('subscription', $felder['mode'] ?? null);
        self::assertSame('month', $felder['line_items'][0]['price_data']['recurring']['interval'] ?? null);
    }

    public function testFehlerantwortWirdZuEinerAusnahme(): void
    {
        $http = new RecordingHttpClient(['{"error":{"message":"Karte abgelehnt"}}']);

        $this->expectException(BillingException::class);
        $this->expectExceptionMessageMatches('/Karte abgelehnt/');

        $this->provider($http)->createCheckout($this->request(), 'https://markt.example/ok', 'https://markt.example/ab');
    }

    // ------------------------------------------------------------- Webhook

    public function testEinRichtigSigniertesEreignisWirdGelesen(): void
    {
        [$payload, $headers] = $this->signedEvent('checkout.session.completed', [
            'id' => 'cs_test_1',
            'amount_total' => 990,
            'currency' => 'eur',
            'subscription' => 'sub_1',
        ]);

        $event = $this->provider()->parseWebhook($payload, $headers);

        self::assertNotNull($event);
        self::assertSame('cs_test_1', $event->providerReference);
        self::assertSame(PaymentStatus::Bezahlt, $event->status);
        $betrag = $event->amount;
        self::assertNotNull($betrag);
        self::assertSame(990, $betrag->cents);
        self::assertSame('EUR', $betrag->currency);
        self::assertSame('sub_1', $event->externalSubscriptionId);
    }

    /**
     * Der entscheidende Test: Ohne gueltige Signatur wird nichts ausgewertet.
     */
    public function testEinGefaelschtesEreignisWirdVerworfen(): void
    {
        [$payload] = $this->signedEvent('checkout.session.completed', ['id' => 'cs_test_1']);

        self::assertNull($this->provider()->parseWebhook($payload, ['stripe-signature' => 't=1,v1=deadbeef']));
        self::assertNull($this->provider()->parseWebhook($payload, []));
        self::assertNull($this->provider()->parseWebhook($payload, ['stripe-signature' => 'unsinn']));
    }

    public function testEinFremdesGeheimnisZaehltNicht(): void
    {
        [$payload, $headers] = $this->signedEvent(
            'checkout.session.completed',
            ['id' => 'cs_test_1'],
            null,
            'whsec_falsch',
        );

        self::assertNull($this->provider()->parseWebhook($payload, $headers));
    }

    /**
     * Eine einmal mitgeschnittene Bestaetigung darf sich nicht beliebig oft
     * erneut einspielen lassen.
     */
    public function testEinAltesEreignisWirdVerworfen(): void
    {
        [$payload, $headers] = $this->signedEvent(
            'checkout.session.completed',
            ['id' => 'cs_test_1'],
            time() - 3600,
        );

        self::assertNull($this->provider()->parseWebhook($payload, $headers));
    }

    public function testEinVeraenderterRumpfWirdVerworfen(): void
    {
        [$payload, $headers] = $this->signedEvent('checkout.session.completed', [
            'id' => 'cs_test_1',
            'amount_total' => 990,
        ]);

        // Betrag nachtraeglich erhoeht — die Signatur passt nicht mehr.
        $manipuliert = str_replace('990', '99000', $payload);

        self::assertNull($this->provider()->parseWebhook($manipuliert, $headers));
    }

    public function testUnbekannteEreignisartenWerdenUebergangen(): void
    {
        [$payload, $headers] = $this->signedEvent('customer.updated', ['id' => 'cus_1']);

        self::assertNull($this->provider()->parseWebhook($payload, $headers));
    }

    public function testFehlgeschlageneUndErstatteteZahlungen(): void
    {
        [$payload, $headers] = $this->signedEvent('invoice.payment_failed', ['id' => 'in_1']);
        self::assertSame(PaymentStatus::Fehlgeschlagen, $this->provider()->parseWebhook($payload, $headers)?->status);

        [$payload, $headers] = $this->signedEvent('charge.refunded', ['id' => 'ch_1']);
        self::assertSame(PaymentStatus::Erstattet, $this->provider()->parseWebhook($payload, $headers)?->status);
    }

    // --------------------------------------------------------- Kuendigung

    public function testKuendigungLaeuftZumPeriodenende(): void
    {
        $http = new RecordingHttpClient(['{"id":"sub_1","cancel_at_period_end":true}']);

        self::assertTrue($this->provider($http)->cancelSubscription('sub_1'));

        $letzte = $http->lastCall();
        self::assertSame('https://api.stripe.test/v1/subscriptions/sub_1', $letzte['url']);

        parse_str($letzte['body'], $felder);
        self::assertSame('true', $felder['cancel_at_period_end'] ?? null);
    }

    public function testOhneSchluesselWirdNichtGekuendigt(): void
    {
        $provider = new StripePaymentProvider('', '', 'https://api.stripe.test/v1');

        self::assertFalse($provider->cancelSubscription('sub_1'));
    }

    private function request(?\Reptilienmarkt\Domain\Billing\BillingInterval $interval = null): CheckoutRequest
    {
        return new CheckoutRequest(
            new User(7, 'zuechter@example.tld', 'Testzüchter'),
            PaymentPurpose::Abo,
            'zuechter',
            'Züchter',
            new Money(990, 'EUR'),
            $interval,
        );
    }
}
