<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Support;

use Reptilienmarkt\Domain\Billing\CheckoutRequest;
use Reptilienmarkt\Domain\Billing\CheckoutSession;
use Reptilienmarkt\Domain\Billing\Money;
use Reptilienmarkt\Domain\Billing\PaymentEvent;
use Reptilienmarkt\Domain\Billing\PaymentProvider;
use Reptilienmarkt\Domain\Billing\PaymentStatus;

/**
 * Ein Zahlungsanbieter, der nichts anruft.
 *
 * So laesst sich der gesamte Kaufweg pruefen — Beleg, Rueckmeldung,
 * Freischaltung — ohne dass ein Test jemals eine fremde Schnittstelle
 * beruehrt.
 */
final class FakePaymentProvider implements PaymentProvider
{
    private int $counter = 0;

    /** @var list<CheckoutRequest> */
    private array $requests = [];

    /** @var list<string> */
    private array $cancelled = [];

    public function name(): string
    {
        return 'fake';
    }

    public function isOperational(): bool
    {
        return true;
    }

    public function createCheckout(CheckoutRequest $request, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $this->requests[] = $request;
        ++$this->counter;

        return new CheckoutSession(
            'https://bezahlen.example/sitzung/' . $this->counter,
            'sess_' . $this->counter,
            $this->name(),
        );
    }

    public function parseWebhook(string $payload, array $headers): ?PaymentEvent
    {
        /** @var mixed $decoded */
        $decoded = json_decode($payload, true);

        if (!\is_array($decoded) || !\is_string($decoded['reference'] ?? null)) {
            return null;
        }

        $status = PaymentStatus::tryFrom(\is_string($decoded['status'] ?? null) ? $decoded['status'] : '');

        if ($status === null) {
            return null;
        }

        return new PaymentEvent(
            $decoded['reference'],
            $status,
            \is_int($decoded['amount'] ?? null) ? new Money($decoded['amount']) : null,
            null,
            'sub_extern_1',
        );
    }

    public function cancelSubscription(string $providerReference): bool
    {
        $this->cancelled[] = $providerReference;

        return true;
    }

    /**
     * Baut die Argumente fuer BillingService::handleWebhook().
     *
     * @return array{string, array<string, string>}
     */
    public function paidEvent(string $reference, ?int $amount = null): array
    {
        return $this->event($reference, PaymentStatus::Bezahlt, $amount);
    }

    /**
     * @return array{string, array<string, string>}
     */
    public function failedEvent(string $reference): array
    {
        return $this->event($reference, PaymentStatus::Fehlgeschlagen, null);
    }

    /**
     * @return list<CheckoutRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @return list<string>
     */
    public function cancelled(): array
    {
        return $this->cancelled;
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function event(string $reference, PaymentStatus $status, ?int $amount): array
    {
        $payload = ['reference' => $reference, 'status' => $status->value];

        if ($amount !== null) {
            $payload['amount'] = $amount;
        }

        return [json_encode($payload, \JSON_THROW_ON_ERROR), []];
    }
}
