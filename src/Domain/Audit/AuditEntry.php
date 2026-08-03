<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Audit;

/**
 * Ein Eintrag im revisionssicheren Verlauf. Die Tabelle audit_log ist per
 * Trigger append-only — Eintraege lassen sich weder aendern noch loeschen.
 */
final readonly class AuditEntry
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $action,
        public string $entityType,
        public ?int $entityId = null,
        public array $data = [],
        public ?int $actorUserId = null,
        public AuditActorType $actorType = AuditActorType::User,
        public ?string $ipAddress = null,
    ) {}
}
