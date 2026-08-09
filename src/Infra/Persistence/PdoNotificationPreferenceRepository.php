<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Notification\NotificationChannel;
use Reptilienmarkt\Domain\Notification\NotificationPreferenceRepository;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoNotificationPreferenceRepository implements NotificationPreferenceRepository
{
    public function __construct(private Database $database) {}

    public function forUser(int $userId): array
    {
        $rows = $this->database->select(
            'SELECT channel_key, enabled FROM notification_preferences WHERE user_id = :id',
            ['id' => $userId],
        );

        $zustand = [];

        foreach ($rows as $row) {
            $zustand[(string) $row['channel_key']] = (int) $row['enabled'] === 1;
        }

        return $zustand;
    }

    public function set(int $userId, NotificationChannel $channel, bool $enabled, DateTimeImmutable $at): void
    {
        $this->database->execute(
            'INSERT INTO notification_preferences (user_id, channel_key, enabled, updated_at)
             VALUES (:user_id, :kanal, :enabled, :now)
             ON CONFLICT (user_id, channel_key) DO UPDATE SET enabled = excluded.enabled, updated_at = excluded.updated_at',
            [
                'user_id' => $userId,
                'kanal' => $channel->value,
                'enabled' => $enabled ? 1 : 0,
                'now' => Timestamp::utc($at),
            ],
        );
    }

    public function storeUnsubscribeHash(int $userId, string $hash, DateTimeImmutable $at): void
    {
        $this->database->execute(
            'UPDATE users SET unsubscribe_token = :hash, unsubscribe_token_at = :now WHERE id = :id',
            ['hash' => $hash, 'now' => Timestamp::utc($at), 'id' => $userId],
        );
    }

    public function findUserIdByUnsubscribeHash(string $hash): ?int
    {
        // Der leere Hash faende sonst jedes Konto ohne Token — ein UNIQUE-Index
        // laesst mehrere NULL zu, aber der Vergleich muss trotzdem ausgeschlossen
        // bleiben.
        if ($hash === '') {
            return null;
        }

        $id = $this->database->scalar(
            'SELECT id FROM users WHERE unsubscribe_token = :hash',
            ['hash' => $hash],
        );

        return is_numeric($id) ? (int) $id : null;
    }
}
