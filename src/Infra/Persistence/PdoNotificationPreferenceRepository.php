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

    public function unsubscribeSecret(int $userId): ?string
    {
        $secret = $this->database->scalar(
            'SELECT unsubscribe_secret FROM users WHERE id = :id',
            ['id' => $userId],
        );

        // Die leere Zeichenkette waere ein Geheimnis, das jeder kennt — sie
        // zaehlt wie "keines", statt einen HMAC mit leerem Schluessel zu bilden.
        return \is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function storeUnsubscribeSecret(int $userId, string $secret, DateTimeImmutable $at): void
    {
        $this->database->execute(
            'UPDATE users SET unsubscribe_secret = :secret, unsubscribe_secret_at = :now WHERE id = :id',
            ['secret' => $secret, 'now' => Timestamp::utc($at), 'id' => $userId],
        );
    }
}
