<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

use Reptilienmarkt\Domain\User\User;

/**
 * Was der Zahlungsanbieter braucht, um eine Bezahlseite zu eroeffnen.
 */
final readonly class CheckoutRequest
{
    public function __construct(
        public User $user,
        public PaymentPurpose $purpose,
        public string $itemKey,
        public string $description,
        public Money $amount,
        public ?BillingInterval $interval = null,
        public ?int $referenceId = null,
    ) {}

    public function isRecurring(): bool
    {
        return $this->interval !== null && $this->interval !== BillingInterval::Keiner;
    }
}
