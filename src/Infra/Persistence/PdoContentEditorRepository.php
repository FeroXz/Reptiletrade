<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Content\ContentEditorRepository;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoContentEditorRepository implements ContentEditorRepository
{
    public function __construct(private Database $database) {}

    public function isEditor(int $userId): bool
    {
        return $this->database->scalar(
            'SELECT 1 FROM content_editors WHERE user_id = :id',
            ['id' => $userId],
        ) !== null;
    }

    public function grant(int $userId, DateTimeImmutable $moment, ?int $grantedBy): void
    {
        // Zweimal ernennen ist kein Fehler, sondern eine Bestaetigung. Das
        // Datum bleibt dabei das der ersten Ernennung.
        $this->database->execute(
            'INSERT INTO content_editors (user_id, granted_at, granted_by)
             VALUES (:id, :now, :by)
             ON CONFLICT (user_id) DO NOTHING',
            ['id' => $userId, 'now' => Timestamp::utc($moment), 'by' => $grantedBy],
        );
    }

    public function revoke(int $userId): void
    {
        $this->database->execute('DELETE FROM content_editors WHERE user_id = :id', ['id' => $userId]);
    }

    public function all(): array
    {
        $editors = [];

        foreach ($this->database->select(
            'SELECT e.user_id, u.email, u.display_name, e.granted_at
               FROM content_editors e JOIN users u ON u.id = e.user_id
              ORDER BY u.display_name ASC',
        ) as $row) {
            $editors[] = [
                'user_id' => (int) $row['user_id'],
                'email' => (string) $row['email'],
                'display_name' => (string) $row['display_name'],
                'granted_at' => (string) $row['granted_at'],
            ];
        }

        return $editors;
    }

    public function count(): int
    {
        return (int) $this->database->scalar('SELECT COUNT(*) FROM content_editors');
    }
}
