<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Content\Redirect;
use Reptilienmarkt\Domain\Content\RedirectRepository;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoRedirectRepository implements RedirectRepository
{
    private const string COLUMNS = 'id, from_path, to_path, code, hits, last_used_at, created_at, created_by, is_auto';

    public function __construct(private Database $database) {}

    public function findByPath(string $fromPath): ?Redirect
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM content_redirects WHERE from_path = :from',
            ['from' => $fromPath],
        );

        return $row === null ? null : self::map($row);
    }

    public function save(Redirect $redirect): int
    {
        if ($redirect->id !== null) {
            $this->database->execute(
                'UPDATE content_redirects SET from_path = :from, to_path = :to, code = :code, is_auto = :auto
                  WHERE id = :id',
                [
                    'from' => $redirect->fromPath,
                    'to' => $redirect->toPath,
                    'code' => $redirect->code,
                    'auto' => $redirect->isAuto ? 1 : 0,
                    'id' => $redirect->id,
                ],
            );

            return $redirect->id;
        }

        $this->database->execute(
            'INSERT INTO content_redirects (from_path, to_path, code, created_at, created_by, is_auto)
             VALUES (:from, :to, :code, :now, :by, :auto)',
            [
                'from' => $redirect->fromPath,
                'to' => $redirect->toPath,
                'code' => $redirect->code,
                'now' => Timestamp::utcOrNull($redirect->createdAt) ?? Timestamp::now(),
                'by' => $redirect->createdBy,
                'auto' => $redirect->isAuto ? 1 : 0,
            ],
        );

        return $this->database->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->database->execute('DELETE FROM content_redirects WHERE id = :id', ['id' => $id]);
    }

    public function deleteByPath(string $fromPath): void
    {
        $this->database->execute('DELETE FROM content_redirects WHERE from_path = :from', ['from' => $fromPath]);
    }

    public function recordHit(int $id, DateTimeImmutable $moment): void
    {
        $this->database->execute(
            'UPDATE content_redirects SET hits = hits + 1, last_used_at = :now WHERE id = :id',
            ['now' => Timestamp::utc($moment), 'id' => $id],
        );
    }

    public function all(int $limit = 200): array
    {
        return array_map(
            static fn(array $row): Redirect => self::map($row),
            $this->database->select(
                'SELECT ' . self::COLUMNS . ' FROM content_redirects ORDER BY created_at DESC, id DESC LIMIT :limit',
                ['limit' => $limit],
            ),
        );
    }

    public function pointingTo(string $toPath): array
    {
        return array_map(
            static fn(array $row): Redirect => self::map($row),
            $this->database->select(
                'SELECT ' . self::COLUMNS . ' FROM content_redirects WHERE to_path = :to',
                ['to' => $toPath],
            ),
        );
    }

    public function loops(): array
    {
        $loops = [];

        // Ein Selbstverbund findet Paare, die aufeinander zeigen. Laengere
        // Kreise entstehen nicht: RedirectService loest Ketten beim Anlegen
        // auf und weist Schleifen ab — was hier auftaucht, kann nur von Hand
        // in die Datenbank geschrieben worden sein.
        foreach ($this->database->select(
            'SELECT a.from_path AS von, a.to_path AS nach
               FROM content_redirects a
               JOIN content_redirects b ON b.from_path = a.to_path AND b.to_path = a.from_path
              ORDER BY a.from_path',
        ) as $row) {
            $loops[] = ['from' => (string) $row['von'], 'to' => (string) $row['nach']];
        }

        return $loops;
    }

    public function count(): int
    {
        return (int) $this->database->scalar('SELECT COUNT(*) FROM content_redirects');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function map(array $row): Redirect
    {
        return new Redirect(
            id: (int) $row['id'],
            fromPath: (string) $row['from_path'],
            toPath: (string) $row['to_path'],
            code: (int) $row['code'],
            hits: (int) $row['hits'],
            lastUsedAt: Timestamp::parse($row['last_used_at'] === null ? null : (string) $row['last_used_at']),
            createdAt: Timestamp::parse((string) $row['created_at']),
            createdBy: $row['created_by'] === null ? null : (int) $row['created_by'],
            isAuto: (int) $row['is_auto'] === 1,
        );
    }
}
