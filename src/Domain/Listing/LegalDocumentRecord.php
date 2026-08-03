<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

use DateTimeImmutable;

final readonly class LegalDocumentRecord
{
    public function __construct(
        public ?int $id,
        public int $listingId,
        public LegalDocType $docType,
        public ?string $referenceNumber = null,
        public ?string $issuingAuthority = null,
        public ?DateTimeImmutable $issueDate = null,
        public ?string $privatePath = null,
        public ?string $originalFilename = null,
        public ?string $mimeType = null,
        public ?int $byteSize = null,
        public ?DateTimeImmutable $verifiedAt = null,
    ) {}

    public function hasFile(): bool
    {
        return $this->privatePath !== null && $this->privatePath !== '';
    }

    public function isVerified(): bool
    {
        return $this->verifiedAt !== null;
    }
}
