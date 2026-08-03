<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use Reptilienmarkt\Domain\User\UserDocument;
use Reptilienmarkt\Domain\User\UserDocumentRepository;
use Reptilienmarkt\Domain\User\UserDocumentStatus;
use Reptilienmarkt\Domain\User\UserDocumentType;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoUserDocumentRepository implements UserDocumentRepository
{
    private const string COLUMNS = 'id, user_id, doc_type, private_path, original_filename, mime_type, byte_size, '
        . 'status, reviewed_by, reviewed_at, review_note, created_at';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?UserDocument
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM user_documents WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->map($row);
    }

    public function forUser(int $userId): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM user_documents WHERE user_id = :user ORDER BY created_at DESC',
            ['user' => $userId],
        );

        return array_map($this->map(...), $rows);
    }

    public function findByType(int $userId, UserDocumentType $type): ?UserDocument
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM user_documents WHERE user_id = :user AND doc_type = :type
             ORDER BY created_at DESC LIMIT 1',
            ['user' => $userId, 'type' => $type->value],
        );

        return $row === null ? null : $this->map($row);
    }

    public function save(UserDocument $document): int
    {
        if ($document->id === null) {
            $this->database->execute(
                'INSERT INTO user_documents
                    (user_id, doc_type, private_path, original_filename, mime_type, byte_size, status, created_at)
                 VALUES (:user, :type, :path, :filename, :mime, :size, :status, :now)',
                [
                    'user' => $document->userId,
                    'type' => $document->docType->value,
                    'path' => $document->privatePath,
                    'filename' => $document->originalFilename,
                    'mime' => $document->mimeType,
                    'size' => $document->byteSize,
                    'status' => $document->status->value,
                    'now' => Timestamp::utcOrNull($document->createdAt) ?? Timestamp::now(),
                ],
            );

            return $this->database->lastInsertId();
        }

        $this->database->execute(
            'UPDATE user_documents SET status = :status, reviewed_by = :reviewer, reviewed_at = :at, review_note = :note
              WHERE id = :id',
            [
                'status' => $document->status->value,
                'reviewer' => $document->reviewedBy,
                'at' => Timestamp::utcOrNull($document->reviewedAt),
                'note' => $document->reviewNote,
                'id' => $document->id,
            ],
        );

        return $document->id;
    }

    public function pending(int $limit = 50): array
    {
        $rows = $this->database->select(
            "SELECT " . self::COLUMNS . " FROM user_documents WHERE status = 'offen'
             ORDER BY created_at ASC LIMIT :limit",
            ['limit' => $limit],
        );

        return array_map($this->map(...), $rows);
    }

    public function delete(int $id): void
    {
        $this->database->execute('DELETE FROM user_documents WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): UserDocument
    {
        return new UserDocument(
            (int) $row['id'],
            (int) $row['user_id'],
            UserDocumentType::from((string) $row['doc_type']),
            (string) $row['private_path'],
            (string) $row['original_filename'],
            (string) $row['mime_type'],
            (int) $row['byte_size'],
            UserDocumentStatus::from((string) $row['status']),
            $row['reviewed_by'] === null ? null : (int) $row['reviewed_by'],
            Timestamp::parse(\is_string($row['reviewed_at']) ? $row['reviewed_at'] : null),
            \is_string($row['review_note']) ? $row['review_note'] : null,
            Timestamp::parse(\is_string($row['created_at']) ? $row['created_at'] : null),
        );
    }
}
