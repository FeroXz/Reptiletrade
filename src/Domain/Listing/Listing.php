<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Geo\Country;

/**
 * Eine Anzeige. Waehrend des Assistenten ist sie ein Entwurf und darf
 * unvollstaendig sein — vollstaendig wird sie erst beim Veroeffentlichen
 * geprueft.
 */
final readonly class Listing
{
    /**
     * @param array<string, scalar|null> $legalConfirmations Bestaetigungen aus Schritt 5
     */
    public function __construct(
        public ?int $id,
        public int $userId,
        public ListingType $type,
        public int $speciesId,
        public string $title = '',
        public string $description = '',
        public ?int $priceCents = null,
        public string $currency = 'EUR',
        public bool $negotiable = false,
        public ?string $tradeWanted = null,
        public Sex $sex = Sex::Unbekannt,
        public ?DateTimeImmutable $hatchDate = null,
        public ?int $weightG = null,
        public int $countAvailable = 1,
        public CbStatus $cbStatus = CbStatus::Unbekannt,
        public ListingStatus $status = ListingStatus::Entwurf,
        public ?string $postalCode = null,
        public ?Country $country = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public Handover $handover = Handover::Abholung,
        public array $legalConfirmations = [],
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    public function isDraft(): bool
    {
        return $this->status === ListingStatus::Entwurf;
    }

    public function belongsTo(int $userId): bool
    {
        return $this->userId === $userId;
    }

    public function hasLocation(): bool
    {
        return $this->postalCode !== null && $this->country !== null;
    }

    public function confirmation(string $key): ?string
    {
        $value = $this->legalConfirmations[$key] ?? null;

        return \is_scalar($value) ? (string) $value : null;
    }
}
