<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Payment;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Infra\Payment\StripePaymentProvider;
use Reptilienmarkt\Tests\Support\RecordingHttpClient;

/**
 * Der Webhook-Endpunkt ist oeffentlich erreichbar. Ohne hinterlegtes Geheimnis
 * darf er nichts annehmen — sonst rechnet jeder die Signatur selbst aus und
 * schickt sich eine bezahlte Rechnung.
 */
#[CoversClass(StripePaymentProvider::class)]
final class WebhookSecretTest extends TestCase
{
    public function testOhneGeheimnisWirdNichtsAngenommen(): void
    {
        $nutzlast = '{"type":"checkout.session.completed","data":{"object":{"id":"cs_1","payment_status":"paid"}}}';
        $zeitpunkt = (string) time();

        // Genau die Signatur, die ein Angreifer mit leerem Schluessel bildet.
        $signatur = hash_hmac('sha256', $zeitpunkt . '.' . $nutzlast, '');

        $provider = new StripePaymentProvider('', '', 'https://api.stripe.test', new RecordingHttpClient());

        self::assertNull($provider->parseWebhook($nutzlast, [
            'stripe-signature' => \sprintf('t=%s,v1=%s', $zeitpunkt, $signatur),
        ]));
        self::assertFalse($provider->isOperational());
    }

    public function testMitGeheimnisGehtEineGueltigeSignaturDurch(): void
    {
        $nutzlast = '{"type":"checkout.session.completed","data":{"object":{"id":"cs_1","payment_status":"paid"}}}';
        $zeitpunkt = (string) time();
        $signatur = hash_hmac('sha256', $zeitpunkt . '.' . $nutzlast, 'whsec_test');

        $provider = new StripePaymentProvider('sk_test', 'whsec_test', 'https://api.stripe.test', new RecordingHttpClient());

        self::assertNotNull($provider->parseWebhook($nutzlast, [
            'stripe-signature' => \sprintf('t=%s,v1=%s', $zeitpunkt, $signatur),
        ]));
    }
}
