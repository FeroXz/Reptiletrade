<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Moderation;

use DateTimeImmutable;

final readonly class Report
{
    public const int MAX_DESCRIPTION_LENGTH = 2000;

    public function __construct(
        public ?int $id,
        public ?int $reporterId,
        public ReportTargetType $targetType,
        public int $targetId,
        public ReportReason $reason,
        public ?string $description = null,
        public ReportStatus $status = ReportStatus::Offen,
        public ?int $handledBy = null,
        public ?DateTimeImmutable $handledAt = null,
        public ?string $resolutionNote = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
