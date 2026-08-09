<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Auth\Session;
use Reptilienmarkt\Domain\Auth\SessionRepository;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoSessionRepository implements SessionRepository
{
    public function __construct(private Database $database) {}

    private const string COLUMNS = 'id, user_id, ip_address, user_agent, payload, two_factor_pending, '
        . 'created_at, last_seen_at, expires_at';

    public function find(string $id): ?Session
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM sessions WHERE id = :id',
            ['id' => $id],
        );

        return $row === null ? null : $this->map($row);
    }

    public function forUser(int $userId): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM sessions
              WHERE user_id = :user AND expires_at > :now
              ORDER BY last_seen_at DESC',
            ['user' => $userId, 'now' => gmdate('Y-m-d\TH:i:s\Z')],
        );

        return array_map($this->map(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Session
    {
        /** @var mixed $payload */
        $payload = json_decode((string) $row['payload'], true);

        return new Session(
            (string) $row['id'],
            $row['user_id'] === null ? null : (int) $row['user_id'],
            \is_array($payload) ? $payload : [],
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['last_seen_at']),
            new DateTimeImmutable((string) $row['expires_at']),
            (bool) $row['two_factor_pending'],
            $row['ip_address'] === null ? null : (string) $row['ip_address'],
            $row['user_agent'] === null ? null : (string) $row['user_agent'],
        );
    }

    public function save(Session $session): void
    {
        $this->database->execute(
            'INSERT INTO sessions (id, user_id, ip_address, user_agent, payload, two_factor_pending, created_at, last_seen_at, expires_at)
             VALUES (:id, :user_id, :ip, :agent, :payload, :two_factor, :created_at, :last_seen, :expires)
             ON CONFLICT(id) DO UPDATE SET
                user_id = excluded.user_id,
                ip_address = excluded.ip_address,
                user_agent = excluded.user_agent,
                payload = excluded.payload,
                two_factor_pending = excluded.two_factor_pending,
                last_seen_at = excluded.last_seen_at,
                expires_at = excluded.expires_at',
            [
                'id' => $session->id,
                'user_id' => $session->userId,
                'ip' => $session->ipAddress,
                'agent' => $session->userAgent === null ? null : mb_substr($session->userAgent, 0, 255),
                'payload' => json_encode($session->payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'two_factor' => $session->twoFactorPending ? 1 : 0,
                'created_at' => Timestamp::utc($session->createdAt),
                'last_seen' => Timestamp::utc($session->lastSeenAt),
                'expires' => Timestamp::utc($session->expiresAt),
            ],
        );
    }

    public function delete(string $id): void
    {
        $this->database->execute('DELETE FROM sessions WHERE id = :id', ['id' => $id]);
    }

    public function deleteForUser(int $userId): void
    {
        $this->database->execute('DELETE FROM sessions WHERE user_id = :user_id', ['user_id' => $userId]);
    }

    public function deleteForUserExcept(int $userId, string $keepId): int
    {
        return $this->database->execute(
            'DELETE FROM sessions WHERE user_id = :user_id AND id <> :behalten',
            ['user_id' => $userId, 'behalten' => $keepId],
        );
    }

    public function deleteExpired(): int
    {
        return $this->database->execute(
            'DELETE FROM sessions WHERE expires_at <= :now',
            ['now' => gmdate('Y-m-d\TH:i:s\Z')],
        );
    }
}
