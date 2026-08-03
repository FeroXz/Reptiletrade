<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Listing\LegalDocType;
use Reptilienmarkt\Domain\Listing\LegalDocumentRecord;
use Reptilienmarkt\Domain\Listing\LegalDocumentRepository;

final readonly class PdoLegalDocumentRepository implements LegalDocumentRepository
{
    private const string COLUMNS = 'id, listing_id, doc_type, reference_number, issuing_authority, issue_date, '
        . 'private_path, original_filename, mime_type, byte_size, verified_at';

    public function __construct(private Database $database) {}

    public function forListing(int $listingId): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM legal_docs WHERE listing_id = :id ORDER BY doc_type',
            ['id' => $listingId],
        );

        return array_map(fn(array $row): LegalDocumentRecord => $this->map($row), $rows);
    }

    public function find(int $id): ?LegalDocumentRecord
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM legal_docs WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->map($row);
    }

    public function findByType(int $listingId, LegalDocType $type): ?LegalDocumentRecord
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM legal_docs WHERE listing_id = :id AND doc_type = :type',
            ['id' => $listingId, 'type' => $type->value],
        );

        return $row === null ? null : $this->map($row);
    }

    public function save(LegalDocumentRecord $record): int
    {
        $existing = $this->findByType($record->listingId, $record->docType);
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $parameters = [
            'listing_id' => $record->listingId,
            'doc_type' => $record->docType->value,
            'reference' => $record->referenceNumber,
            'authority' => $record->issuingAuthority,
            'issue_date' => $record->issueDate?->format('Y-m-d'),
            'private_path' => $record->privatePath,
            'filename' => $record->originalFilename,
            'mime' => $record->mimeType,
            'bytes' => $record->byteSize,
            'now' => $now,
        ];

        if ($existing === null) {
            $this->database->execute(
                'INSERT INTO legal_docs (listing_id, doc_type, reference_number, issuing_authority, issue_date,
                                         private_path, original_filename, mime_type, byte_size, created_at, updated_at)
                 VALUES (:listing_id, :doc_type, :reference, :authority, :issue_date,
                         :private_path, :filename, :mime, :bytes, :now, :now)',
                $parameters,
            );

            return $this->database->lastInsertId();
        }

        // Eine neu hochgeladene Datei setzt die Freigabe zurueck: Geprueft wurde
        // die alte Fassung, nicht die neue.
        $this->database->execute(
            'UPDATE legal_docs SET
                reference_number = :reference,
                issuing_authority = :authority,
                issue_date = :issue_date,
                private_path = COALESCE(:private_path, private_path),
                original_filename = COALESCE(:filename, original_filename),
                mime_type = COALESCE(:mime, mime_type),
                byte_size = COALESCE(:bytes, byte_size),
                verified_at = CASE WHEN :private_path IS NULL THEN verified_at ELSE NULL END,
                verified_by = CASE WHEN :private_path IS NULL THEN verified_by ELSE NULL END,
                updated_at = :now
              WHERE id = :id',
            $parameters + ['id' => $existing->id],
        );

        return (int) $existing->id;
    }

    public function delete(int $id): void
    {
        $this->database->execute('DELETE FROM legal_docs WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): LegalDocumentRecord
    {
        return new LegalDocumentRecord(
            (int) $row['id'],
            (int) $row['listing_id'],
            LegalDocType::from((string) $row['doc_type']),
            $row['reference_number'] === null ? null : (string) $row['reference_number'],
            $row['issuing_authority'] === null ? null : (string) $row['issuing_authority'],
            $this->date($row['issue_date']),
            $row['private_path'] === null ? null : (string) $row['private_path'],
            $row['original_filename'] === null ? null : (string) $row['original_filename'],
            $row['mime_type'] === null ? null : (string) $row['mime_type'],
            $row['byte_size'] === null ? null : (int) $row['byte_size'],
            $this->date($row['verified_at']),
        );
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return \is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
