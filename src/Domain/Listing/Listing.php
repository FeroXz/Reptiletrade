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
     * Hoechster Preis, den eine Anzeige tragen kann — eine Million Euro in Cent.
     */
    public const int MAX_PRICE_CENTS = 100000000;

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

    /**
     * Der Preis aus einem Formularfeld, in Cent.
     *
     * Die Umrechnung steht hier und nicht in den beiden Controllern, die sie
     * brauchen (Assistent und Nachbearbeitung), damit ein Preis auf beiden
     * Wegen dieselben Grenzen hat.
     *
     * Ohne Grenzen liesse "-20" einen negativen Preis in die Datenbank, und
     * eine Angabe wie "1e30" ergaebe nach (int) round(...) irgendeine Zahl aus
     * dem Ueberlauf — im Zweifel eine grosse negative. Beides sieht in der
     * Liste aus wie ein Preis und ist keiner. Wer mehr als MAX_PRICE_CENTS
     * eintraegt, bekommt die Obergrenze; das ist sichtbar falsch und damit
     * korrigierbar, waehrend eine stillschweigend verworfene Eingabe es nicht
     * ist.
     */
    public static function priceCentsFromInput(string $input): ?int
    {
        $normalised = str_replace(',', '.', trim($input));

        if (!is_numeric($normalised)) {
            return null;
        }

        $cents = round((float) $normalised * 100);

        if ($cents <= 0.0) {
            return 0;
        }

        return (int) min($cents, (float) self::MAX_PRICE_CENTS);
    }
}
