<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

use DateTimeImmutable;
use Exception;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Listing\CbStatus;
use Reptilienmarkt\Domain\Listing\Handover;
use Reptilienmarkt\Domain\Listing\LegalDocType;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Species\Species;

/**
 * Alles, was die Rechts-Engine ueber eine Anzeige wissen muss. Bewusst ein
 * eigenes Objekt und nicht die Listing-Entitaet: Die Pruefung laeuft schon
 * waehrend des Anlegens, wenn es noch gar keine gespeicherte Anzeige gibt.
 */
final readonly class LegalContext
{
    /**
     * @param array<string, LegalDocumentInput> $documents     Schluessel ist LegalDocType->value
     * @param array<string, scalar|null>        $confirmations Bestaetigungen aus dem Formular
     */
    public function __construct(
        public Species $species,
        public ListingType $type,
        public Country $country,
        public Handover $handover,
        public SellerProfile $seller,
        public ?string $admin1 = null,
        public ?DateTimeImmutable $hatchDate = null,
        public ?int $weightG = null,
        public CbStatus $cbStatus = CbStatus::Unbekannt,
        public array $documents = [],
        public array $confirmations = [],
    ) {}

    public function document(LegalDocType $type): ?LegalDocumentInput
    {
        return $this->documents[$type->value] ?? null;
    }

    public function hasDocument(LegalDocType $type): bool
    {
        return $this->document($type)?->isProvided() ?? false;
    }

    public function confirmationBool(string $key): bool
    {
        $value = $this->confirmations[$key] ?? null;

        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return $value === 1;
        }

        return \is_string($value) && \in_array(strtolower($value), ['1', 'true', 'ja', 'yes'], true);
    }

    public function confirmationString(string $key): ?string
    {
        $value = $this->confirmations[$key] ?? null;
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Datumsangaben kommen als ISO-8601-String aus dem Formular.
     */
    public function confirmationDate(string $key): ?DateTimeImmutable
    {
        $value = $this->confirmationString($key);
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Ein Gesuch beschreibt kein konkretes Tier — Nachweis- und Tierschutzregeln
     * greifen dort nicht.
     */
    public function describesAnimalOnOffer(): bool
    {
        return $this->type->describesAnimalOnOffer();
    }

    /**
     * Zustaendige Rechtsordnung fuer die Auswahl des Hinweistextes.
     */
    public function jurisdiction(): string
    {
        return $this->country->value;
    }
}
