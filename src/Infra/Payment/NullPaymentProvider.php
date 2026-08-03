<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Payment;

use Reptilienmarkt\Domain\Billing\BillingException;
use Reptilienmarkt\Domain\Billing\CheckoutRequest;
use Reptilienmarkt\Domain\Billing\CheckoutSession;
use Reptilienmarkt\Domain\Billing\PaymentEvent;
use Reptilienmarkt\Domain\Billing\PaymentProvider;

/**
 * Der Anbieter, der nichts kann — und das offen sagt.
 *
 * Voreinstellung, solange die Monetarisierung nicht aktiviert ist. Er
 * verweigert jeden Zahlungsvorgang mit einer klaren Meldung, statt still zu
 * scheitern oder eine Bezahlung vorzutaeuschen. Genau darin liegt sein Wert:
 * Eine versehentlich freigeschaltete Kaufseite fuehrt hier zu einem
 * sichtbaren Fehler und nicht zu einer halb angelegten Bestellung.
 */
final readonly class NullPaymentProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'keiner';
    }

    public function isOperational(): bool
    {
        return false;
    }

    public function createCheckout(CheckoutRequest $request, string $successUrl, string $cancelUrl): CheckoutSession
    {
        throw new BillingException(
            'Es ist kein Zahlungsanbieter eingerichtet. Die Monetarisierung ist vorbereitet, aber nicht aktiviert.',
        );
    }

    public function parseWebhook(string $payload, array $headers): ?PaymentEvent
    {
        return null;
    }

    public function cancelSubscription(string $providerReference): bool
    {
        return false;
    }
}
