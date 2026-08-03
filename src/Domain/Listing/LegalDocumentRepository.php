<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

interface LegalDocumentRepository
{
    /**
     * @return list<LegalDocumentRecord>
     */
    public function forListing(int $listingId): array;

    public function find(int $id): ?LegalDocumentRecord;

    public function findByType(int $listingId, LegalDocType $type): ?LegalDocumentRecord;

    /**
     * Legt an oder aktualisiert anhand (listing_id, doc_type).
     */
    public function save(LegalDocumentRecord $record): int;

    public function delete(int $id): void;
}
