<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Audit;

interface AuditLog
{
    public function record(AuditEntry $entry): void;

    /**
     * @return list<array{occurred_at: string, action: string, entity_type: string, entity_id: int|null, data: array<string, mixed>}>
     */
    public function forEntity(string $entityType, int $entityId, int $limit = 50): array;
}
