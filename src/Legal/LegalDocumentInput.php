<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Listing\LegalDocType;

/**
 * Was der Nutzer im Formular zu einem Rechtsnachweis angegeben hat.
 * Die Datei selbst liegt ausserhalb des Webroots; hier zaehlt nur, ob sie da ist.
 */
final readonly class LegalDocumentInput
{
    public function __construct(
        public LegalDocType $type,
        public ?string $referenceNumber = null,
        public ?string $issuingAuthority = null,
        public ?DateTimeImmutable $issueDate = null,
        public bool $fileUploaded = false,
    ) {}

    public function hasReferenceNumber(): bool
    {
        return $this->referenceNumber !== null && trim($this->referenceNumber) !== '';
    }

    public function hasIssuingAuthority(): bool
    {
        return $this->issuingAuthority !== null && trim($this->issuingAuthority) !== '';
    }

    /**
     * Ein Nachweis gilt als vorhanden, wenn entweder eine Datei hochgeladen
     * oder eine Referenznummer angegeben wurde.
     */
    public function isProvided(): bool
    {
        return $this->fileUploaded || $this->hasReferenceNumber();
    }
}
