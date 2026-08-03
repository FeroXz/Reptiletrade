<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

/**
 * Eine Rueckmeldung des Zahlungsanbieters, uebersetzt in die Begriffe dieser
 * Anwendung. Was der Anbieter im Einzelnen schickt, bleibt in seiner
 * Umsetzung — hier kommt nur an, was fachlich zaehlt.
 */
final readonly class PaymentEvent
{
    public function __construct(
        public string $providerReference,
        public PaymentStatus $status,
        public ?Money $amount = null,
        public ?string $externalCustomerId = null,
        public ?string $externalSubscriptionId = null,
    ) {}
}
