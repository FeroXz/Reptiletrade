<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Moderation;

use DateTimeImmutable;

interface ReportRepository
{
    public function findById(int $id): ?Report;

    public function save(Report $report): int;

    /**
     * Offene Meldungen fuer die Moderationsliste.
     *
     * @return list<Report>
     */
    public function queue(int $limit = 50, int $offset = 0): array;

    public function openCount(): int;

    /**
     * Hat dieser Nutzer dieses Ziel schon gemeldet? Verhindert Mehrfachmeldungen
     * derselben Person.
     */
    public function alreadyReported(int $reporterId, ReportTargetType $type, int $targetId): bool;

    /**
     * @return list<Report>
     */
    public function forTarget(ReportTargetType $type, int $targetId): array;

    public function resolve(int $reportId, ReportStatus $status, int $moderatorId, ?string $note, DateTimeImmutable $at): void;
}
