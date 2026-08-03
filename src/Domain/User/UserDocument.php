<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

use DateTimeImmutable;

/**
 * Ein Nachweis zur Konto-Verifizierung. Die Datei liegt wie die Rechtsnachweise
 * ausserhalb des Webroots; hier steht nur der relative Pfad.
 */
final readonly class UserDocument
{
    public function __construct(
        public ?int $id,
        public int $userId,
        public UserDocumentType $docType,
        public string $privatePath,
        public string $originalFilename,
        public string $mimeType,
        public int $byteSize,
        public UserDocumentStatus $status = UserDocumentStatus::Offen,
        public ?int $reviewedBy = null,
        public ?DateTimeImmutable $reviewedAt = null,
        public ?string $reviewNote = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    public function isPending(): bool
    {
        return $this->status === UserDocumentStatus::Offen;
    }
}
