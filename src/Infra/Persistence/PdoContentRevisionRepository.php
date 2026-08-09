<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Content\ContentRevision;
use Reptilienmarkt\Domain\Content\ContentRevisionRepository;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoContentRevisionRepository implements ContentRevisionRepository
{
    public function __construct(private Database $database) {}

    public function append(
        int $entryId,
        array $snapshot,
        ?int $authorId,
        string $comment,
        DateTimeImmutable $moment,
    ): int {
        // Nummer holen und schreiben in einer Transaktion: Zwei gleichzeitige
        // Speichervorgaenge duerfen nicht dieselbe Nummer bekommen — der
        // Primaerschluessel wiese den zweiten sonst ab, und ein Speichern, das
        // an der Versionsverwaltung scheitert, waere schwer zu erklaeren.
        return $this->database->transaction(
            static function (Database $database) use ($entryId, $snapshot, $authorId, $comment, $moment): int {
                $next = (int) $database->scalar(
                    'SELECT COALESCE(MAX(revision_no), 0) + 1 FROM content_revisions WHERE entry_id = :entry',
                    ['entry' => $entryId],
                );

                $database->execute(
                    'INSERT INTO content_revisions (entry_id, revision_no, snapshot_json, author_id, comment, created_at)
                     VALUES (:entry, :no, :snapshot, :author, :comment, :now)',
                    [
                        'entry' => $entryId,
                        'no' => $next,
                        'snapshot' => json_encode($snapshot, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                        'author' => $authorId,
                        'comment' => $comment,
                        'now' => Timestamp::utc($moment),
                    ],
                );

                return $next;
            },
        );
    }

    public function find(int $entryId, int $revisionNo): ?ContentRevision
    {
        $row = $this->database->selectOne(
            'SELECT entry_id, revision_no, snapshot_json, author_id, comment, created_at
               FROM content_revisions WHERE entry_id = :entry AND revision_no = :no',
            ['entry' => $entryId, 'no' => $revisionNo],
        );

        return $row === null ? null : self::map($row);
    }

    public function forEntry(int $entryId, int $limit = 50): array
    {
        $revisions = [];

        foreach ($this->database->select(
            'SELECT entry_id, revision_no, snapshot_json, author_id, comment, created_at
               FROM content_revisions WHERE entry_id = :entry
              ORDER BY revision_no DESC LIMIT :limit',
            ['entry' => $entryId, 'limit' => $limit],
        ) as $row) {
            $revisions[] = self::map($row);
        }

        return $revisions;
    }

    public function prune(int $entryId, int $keep): int
    {
        if ($keep < 1) {
            // 0 hiesse "alle wegwerfen" — das ist nie gemeint und waere ein
            // stiller Datenverlust. Ein Konfigurationsfehler wird oben laut,
            // hier wird nichts getan.
            return 0;
        }

        return $this->database->execute(
            'DELETE FROM content_revisions
              WHERE entry_id = :entry
                AND revision_no <= (
                    SELECT MAX(revision_no) - :keep FROM content_revisions WHERE entry_id = :entry
                )',
            ['entry' => $entryId, 'keep' => $keep],
        );
    }

    public function count(int $entryId): int
    {
        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM content_revisions WHERE entry_id = :entry',
            ['entry' => $entryId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function map(array $row): ContentRevision
    {
        /** @var mixed $snapshot */
        $snapshot = json_decode((string) $row['snapshot_json'], true);

        $clean = [];
        if (\is_array($snapshot)) {
            foreach ($snapshot as $key => $value) {
                $clean[(string) $key] = $value;
            }
        }

        return new ContentRevision(
            (int) $row['entry_id'],
            (int) $row['revision_no'],
            $clean,
            $row['author_id'] === null ? null : (int) $row['author_id'],
            (string) $row['comment'],
            Timestamp::parse((string) $row['created_at']),
        );
    }
}
