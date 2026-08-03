<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Auth\Session;
use Reptilienmarkt\Domain\Auth\SessionRepository;

final readonly class PdoSessionRepository implements SessionRepository
{
    public function __construct(private Database $database) {}

    public function find(string $id): ?Session
    {
        $row = $this->database->selectOne(
            'SELECT id, user_id, ip_address, user_agent, payload, two_factor_pending, created_at, last_seen_at, expires_at
               FROM sessions WHERE id = :id',
            ['id' => $id],
        );

        if ($row === null) {
            return null;
        }

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
                'created_at' => $session->createdAt->format('Y-m-d\TH:i:s\Z'),
                'last_seen' => $session->lastSeenAt->format('Y-m-d\TH:i:s\Z'),
                'expires' => $session->expiresAt->format('Y-m-d\TH:i:s\Z'),
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

    public function deleteExpired(): int
    {
        return $this->database->execute(
            'DELETE FROM sessions WHERE expires_at <= :now',
            ['now' => gmdate('Y-m-d\TH:i:s\Z')],
        );
    }
}
