<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Moderation;

use Reptilienmarkt\Domain\Audit\AuditActorType;
use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Domain\Trust\RateLimitExceededException;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Support\Clock;

/**
 * Meldungen aus dem Bestand und ihre Bearbeitung.
 *
 * Gemeldet werden darf auch ohne Konto nicht — sonst waere der Meldebutton
 * selbst ein Werkzeug zum Zumuellen der Moderation. Dieselbe Person kann
 * dasselbe Ziel nur einmal melden; wer trotzdem erneut klickt, bekommt keine
 * Fehlermeldung, sondern dieselbe Bestaetigung wie beim ersten Mal.
 */
final readonly class ReportService
{
    public function __construct(
        private ReportRepository $reports,
        private RateLimiter $rateLimiter,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    /**
     * @throws RateLimitExceededException
     * @throws ReportException
     */
    public function report(
        User $reporter,
        ReportTargetType $type,
        int $targetId,
        ReportReason $reason,
        ?string $description = null,
        ?string $ipAddress = null,
    ): Report {
        $reporterId = $reporter->id ?? 0;

        if ($type === ReportTargetType::User && $targetId === $reporterId) {
            throw new ReportException('Du kannst dich nicht selbst melden.');
        }

        // Die Doppelmeldung faellt still durch: Wer zweimal klickt, hat nichts
        // falsch gemacht, und die Moderation braucht den Eintrag nicht doppelt.
        if ($this->reports->alreadyReported($reporterId, $type, $targetId)) {
            $existing = $this->reports->forTarget($type, $targetId);
            foreach ($existing as $report) {
                if ($report->reporterId === $reporterId) {
                    return $report;
                }
            }
        }

        $this->guardRateLimits($reporterId, $ipAddress);

        $description = $description === null ? null : trim($description);
        if ($description === '') {
            $description = null;
        }

        if ($description !== null && mb_strlen($description) > Report::MAX_DESCRIPTION_LENGTH) {
            $description = mb_substr($description, 0, Report::MAX_DESCRIPTION_LENGTH);
        }

        $id = $this->reports->save(new Report(
            null,
            $reporterId,
            $type,
            $targetId,
            $reason,
            $description,
            createdAt: $this->clock->now(),
        ));

        $this->audit->record(new AuditEntry(
            'report.created',
            $type->value,
            $targetId,
            ['grund' => $reason->value, 'report_id' => $id],
            $reporter->id,
            ipAddress: $ipAddress,
        ));

        return $this->reports->findById($id) ?? throw new ReportException('Die Meldung konnte nicht gespeichert werden.');
    }

    /**
     * @throws ReportException
     */
    public function resolve(Report $report, User $moderator, ReportStatus $status, ?string $note = null): void
    {
        if (!$moderator->isModerator()) {
            throw new ReportException('Nur die Moderation kann Meldungen bearbeiten.');
        }

        if ($status->isOpen() && $status !== ReportStatus::InPruefung) {
            throw new ReportException('Eine Meldung lässt sich nicht auf "offen" zurücksetzen.');
        }

        $this->reports->resolve($report->id ?? 0, $status, $moderator->id ?? 0, $note, $this->clock->now());

        $this->audit->record(new AuditEntry(
            'report.resolved',
            'report',
            $report->id,
            ['status' => $status->value, 'ziel' => $report->targetType->value, 'ziel_id' => $report->targetId],
            $moderator->id,
            AuditActorType::Admin,
        ));
    }

    /**
     * @return list<Report>
     */
    public function queue(int $limit = 50, int $offset = 0): array
    {
        return $this->reports->queue($limit, $offset);
    }

    public function openCount(): int
    {
        return $this->reports->openCount();
    }

    /**
     * @throws RateLimitExceededException
     */
    private function guardRateLimits(int $reporterId, ?string $ipAddress): void
    {
        $perAccount = $this->rateLimiter->attempt('meldung.konto', (string) $reporterId);
        if (!$perAccount->allowed) {
            throw new RateLimitExceededException($perAccount);
        }

        if ($ipAddress === null) {
            return;
        }

        $perIp = $this->rateLimiter->attempt('meldung.ip', $ipAddress);
        if (!$perIp->allowed) {
            throw new RateLimitExceededException($perIp);
        }
    }
}
