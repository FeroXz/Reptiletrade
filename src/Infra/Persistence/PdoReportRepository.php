<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Moderation\Report;
use Reptilienmarkt\Domain\Moderation\ReportReason;
use Reptilienmarkt\Domain\Moderation\ReportRepository;
use Reptilienmarkt\Domain\Moderation\ReportStatus;
use Reptilienmarkt\Domain\Moderation\ReportTargetType;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoReportRepository implements ReportRepository
{
    private const string COLUMNS = 'id, reporter_id, target_type, target_id, reason, description, status, '
        . 'handled_by, handled_at, resolution_note, created_at';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?Report
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM reports WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->map($row);
    }

    public function save(Report $report): int
    {
        $this->database->execute(
            'INSERT INTO reports (reporter_id, target_type, target_id, reason, description, status, created_at)
             VALUES (:reporter, :type, :target, :reason, :description, :status, :now)',
            [
                'reporter' => $report->reporterId,
                'type' => $report->targetType->value,
                'target' => $report->targetId,
                'reason' => $report->reason->value,
                'description' => $report->description,
                'status' => $report->status->value,
                'now' => Timestamp::utcOrNull($report->createdAt) ?? Timestamp::now(),
            ],
        );

        return $this->database->lastInsertId();
    }

    public function queue(int $limit = 50, int $offset = 0): array
    {
        // Tierschutz und Betrug zuerst, danach nach Alter. Die Reihenfolge der
        // Gruende steckt in ReportReason::priority(); hier steht sie noch einmal
        // als CASE, weil SQLite die Sortierung machen muss.
        $rows = $this->database->select(
            <<<'SQL'
                SELECT id, reporter_id, target_type, target_id, reason, description, status,
                       handled_by, handled_at, resolution_note, created_at
                  FROM reports
                 WHERE status IN ('offen','in_pruefung')
                 ORDER BY CASE reason
                              WHEN 'tierschutz'  THEN 0
                              WHEN 'betrug'      THEN 1
                              WHEN 'falsche_art' THEN 2
                              WHEN 'spam'        THEN 3
                              ELSE 4
                          END,
                          created_at ASC
                 LIMIT :limit OFFSET :offset
                SQL,
            ['limit' => $limit, 'offset' => $offset],
        );

        return array_map($this->map(...), $rows);
    }

    public function openCount(): int
    {
        $value = $this->database->scalar("SELECT COUNT(*) FROM reports WHERE status IN ('offen','in_pruefung')");

        return (int) (is_numeric($value) ? $value : 0);
    }

    public function alreadyReported(int $reporterId, ReportTargetType $type, int $targetId): bool
    {
        $value = $this->database->scalar(
            'SELECT COUNT(*) FROM reports
              WHERE reporter_id = :reporter AND target_type = :type AND target_id = :target',
            ['reporter' => $reporterId, 'type' => $type->value, 'target' => $targetId],
        );

        return (int) (is_numeric($value) ? $value : 0) > 0;
    }

    public function forTarget(ReportTargetType $type, int $targetId): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM reports WHERE target_type = :type AND target_id = :target
             ORDER BY created_at DESC',
            ['type' => $type->value, 'target' => $targetId],
        );

        return array_map($this->map(...), $rows);
    }

    public function resolve(int $reportId, ReportStatus $status, int $moderatorId, ?string $note, DateTimeImmutable $at): void
    {
        $this->database->execute(
            'UPDATE reports SET status = :status, handled_by = :moderator, handled_at = :at, resolution_note = :note
              WHERE id = :id',
            [
                'status' => $status->value,
                'moderator' => $moderatorId,
                'at' => Timestamp::utc($at),
                'note' => $note,
                'id' => $reportId,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Report
    {
        return new Report(
            (int) $row['id'],
            $row['reporter_id'] === null ? null : (int) $row['reporter_id'],
            ReportTargetType::from((string) $row['target_type']),
            (int) $row['target_id'],
            ReportReason::from((string) $row['reason']),
            \is_string($row['description']) ? $row['description'] : null,
            ReportStatus::from((string) $row['status']),
            $row['handled_by'] === null ? null : (int) $row['handled_by'],
            Timestamp::parse(\is_string($row['handled_at']) ? $row['handled_at'] : null),
            \is_string($row['resolution_note']) ? $row['resolution_note'] : null,
            Timestamp::parse(\is_string($row['created_at']) ? $row['created_at'] : null),
        );
    }
}
