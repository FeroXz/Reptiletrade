<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Legal\LegalText;
use Reptilienmarkt\Legal\LegalTextRepository;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoLegalTextRepository implements LegalTextRepository
{
    private const string COLUMNS = 'id, text_key, title, body, source_reference, jurisdiction, last_reviewed_at';

    public function __construct(private Database $database) {}

    public function find(string $key, string $jurisdiction = 'DE'): ?LegalText
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM legal_texts WHERE text_key = :key AND jurisdiction = :jurisdiction',
            ['key' => $key, 'jurisdiction' => $jurisdiction],
        );

        return $row === null ? null : $this->map($row);
    }

    public function all(): array
    {
        $rows = $this->database->select('SELECT ' . self::COLUMNS . ' FROM legal_texts ORDER BY text_key, jurisdiction');

        return array_map(fn(array $row): LegalText => $this->map($row), $rows);
    }

    public function save(LegalText $text): int
    {
        $this->database->execute(
            'INSERT INTO legal_texts (text_key, title, body, source_reference, jurisdiction, last_reviewed_at, created_at, updated_at)
             VALUES (:key, :title, :body, :source, :jurisdiction, :reviewed, :now, :now)
             ON CONFLICT(text_key, jurisdiction) DO UPDATE SET
                title = excluded.title,
                body = excluded.body,
                source_reference = excluded.source_reference,
                last_reviewed_at = excluded.last_reviewed_at,
                updated_at = excluded.updated_at',
            $this->parameters($text),
        );

        return $this->idOf($text->key, $text->jurisdiction);
    }

    public function insertIfMissing(LegalText $text): bool
    {
        $affected = $this->database->execute(
            'INSERT INTO legal_texts (text_key, title, body, source_reference, jurisdiction, last_reviewed_at, created_at, updated_at)
             VALUES (:key, :title, :body, :source, :jurisdiction, :reviewed, :now, :now)
             ON CONFLICT(text_key, jurisdiction) DO NOTHING',
            $this->parameters($text),
        );

        return $affected > 0;
    }

    public function markReviewed(string $key, string $jurisdiction, int $userId): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->database->execute(
            'UPDATE legal_texts
                SET last_reviewed_at = :now, last_reviewed_by = :user, updated_at = :now
              WHERE text_key = :key AND jurisdiction = :jurisdiction',
            ['now' => $now, 'user' => $userId, 'key' => $key, 'jurisdiction' => $jurisdiction],
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function parameters(LegalText $text): array
    {
        return [
            'key' => $text->key,
            'title' => $text->title,
            'body' => $text->body,
            'source' => $text->sourceReference,
            'jurisdiction' => $text->jurisdiction,
            'reviewed' => Timestamp::utcOrNull($text->lastReviewedAt),
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    private function idOf(string $key, string $jurisdiction): int
    {
        $id = $this->database->scalar(
            'SELECT id FROM legal_texts WHERE text_key = :key AND jurisdiction = :jurisdiction',
            ['key' => $key, 'jurisdiction' => $jurisdiction],
        );

        return (int) (is_numeric($id) ? $id : 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): LegalText
    {
        $reviewed = $row['last_reviewed_at'];

        return new LegalText(
            (int) $row['id'],
            (string) $row['text_key'],
            (string) $row['title'],
            (string) $row['body'],
            (string) $row['jurisdiction'],
            $row['source_reference'] === null ? null : (string) $row['source_reference'],
            \is_string($reviewed) && $reviewed !== '' ? new DateTimeImmutable($reviewed) : null,
        );
    }
}
