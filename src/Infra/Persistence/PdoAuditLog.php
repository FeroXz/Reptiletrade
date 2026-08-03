<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;

final readonly class PdoAuditLog implements AuditLog
{
    public function __construct(private Database $database) {}

    public function record(AuditEntry $entry): void
    {
        $this->database->execute(
            'INSERT INTO audit_log (occurred_at, actor_type, actor_user_id, action, entity_type, entity_id, data_json, ip_address)
             VALUES (:now, :actor_type, :actor_user_id, :action, :entity_type, :entity_id, :data, :ip)',
            [
                'now' => gmdate('Y-m-d\TH:i:s\Z'),
                'actor_type' => $entry->actorType->value,
                'actor_user_id' => $entry->actorUserId,
                'action' => $entry->action,
                'entity_type' => $entry->entityType,
                'entity_id' => $entry->entityId,
                'data' => json_encode($entry->data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'ip' => $entry->ipAddress,
            ],
        );
    }

    public function forEntity(string $entityType, int $entityId, int $limit = 50): array
    {
        $rows = $this->database->select(
            'SELECT occurred_at, action, entity_type, entity_id, data_json
               FROM audit_log
              WHERE entity_type = :type AND entity_id = :id
              ORDER BY occurred_at DESC, id DESC
              LIMIT :limit',
            ['type' => $entityType, 'id' => $entityId, 'limit' => $limit],
        );

        $entries = [];
        foreach ($rows as $row) {
            /** @var mixed $data */
            $data = json_decode((string) $row['data_json'], true);

            $entries[] = [
                'occurred_at' => (string) $row['occurred_at'],
                'action' => (string) $row['action'],
                'entity_type' => (string) $row['entity_type'],
                'entity_id' => $row['entity_id'] === null ? null : (int) $row['entity_id'],
                'data' => \is_array($data) ? $data : [],
            ];
        }

        return $entries;
    }
}
