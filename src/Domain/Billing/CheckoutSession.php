<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

/**
 * Das Ergebnis einer eroeffneten Bezahlung: wohin der Nutzer geschickt wird
 * und unter welcher Kennung die Zahlung beim Anbieter laeuft.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $redirectUrl,
        public string $providerReference,
        public string $provider,
    ) {}
}
