<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Auth\TokenRecord;
use Reptilienmarkt\Domain\Auth\TokenRepository;
use Reptilienmarkt\Domain\Auth\TokenType;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoTokenRepository implements TokenRepository
{
    public function __construct(private Database $database) {}

    public function store(int $userId, TokenType $type, string $tokenHash, DateTimeImmutable $expiresAt): int
    {
        $this->database->execute(
            'INSERT INTO user_tokens (user_id, type, token_hash, created_at, expires_at)
             VALUES (:user_id, :type, :hash, :now, :expires)',
            [
                'user_id' => $userId,
                'type' => $type->value,
                'hash' => $tokenHash,
                'now' => Timestamp::now(),
                'expires' => Timestamp::utc($expiresAt),
            ],
        );

        return $this->database->lastInsertId();
    }

    public function findUnusedByHash(string $tokenHash): ?TokenRecord
    {
        $row = $this->database->selectOne(
            'SELECT id, user_id, type, created_at, expires_at, used_at
               FROM user_tokens
              WHERE token_hash = :hash AND used_at IS NULL',
            ['hash' => $tokenHash],
        );

        if ($row === null) {
            return null;
        }

        $type = TokenType::tryFrom((string) $row['type']);

        return $type === null ? null : new TokenRecord(
            (int) $row['id'],
            (int) $row['user_id'],
            $type,
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['expires_at']),
            \is_string($row['used_at']) ? new DateTimeImmutable($row['used_at']) : null,
        );
    }

    public function markUsed(int $tokenId, DateTimeImmutable $at): void
    {
        $this->database->execute(
            'UPDATE user_tokens SET used_at = :now WHERE id = :id AND used_at IS NULL',
            ['now' => Timestamp::utc($at), 'id' => $tokenId],
        );
    }

    public function invalidateAll(int $userId, TokenType $type, DateTimeImmutable $at): int
    {
        return $this->database->execute(
            'UPDATE user_tokens SET used_at = :now
              WHERE user_id = :user_id AND type = :type AND used_at IS NULL',
            ['now' => Timestamp::utc($at), 'user_id' => $userId, 'type' => $type->value],
        );
    }

    public function purgeExpired(DateTimeImmutable $before): int
    {
        return $this->database->execute(
            'DELETE FROM user_tokens WHERE expires_at < :before',
            ['before' => Timestamp::utc($before)],
        );
    }
}
