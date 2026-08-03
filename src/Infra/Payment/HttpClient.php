<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Payment;

/**
 * Der schmale HTTP-Zugang der Zahlungsanbieter. Als Interface, damit sich der
 * Anbieter ohne Netzzugriff testen laesst — ein Test, der wirklich Stripe
 * anruft, ist kein Test.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     *
     * @throws \Reptilienmarkt\Domain\Billing\BillingException
     */
    public function post(string $url, string $body, array $headers = []): string;
}
