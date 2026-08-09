<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Content\PreviewTokenRepository;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoPreviewTokenRepository implements PreviewTokenRepository
{
    public function __construct(private Database $database) {}

    public function store(string $tokenHash, int $entryId, DateTimeImmutable $expiresAt, ?int $createdBy, DateTimeImmutable $now): void
    {
        $this->database->execute(
            'INSERT INTO content_preview_tokens (token_hash, entry_id, expires_at, created_at, created_by)
             VALUES (:hash, :entry, :expires, :now, :by)',
            [
                'hash' => $tokenHash,
                'entry' => $entryId,
                'expires' => Timestamp::utc($expiresAt),
                'now' => Timestamp::utc($now),
                'by' => $createdBy,
            ],
        );
    }

    public function resolve(string $tokenHash, DateTimeImmutable $now): ?int
    {
        // Der Ablauf wird in der Abfrage geprueft, nicht danach: Sonst haengt
        // die Gueltigkeit daran, dass jeder Aufrufer daran denkt.
        $id = $this->database->scalar(
            'SELECT entry_id FROM content_preview_tokens
              WHERE token_hash = :hash AND expires_at > :now',
            ['hash' => $tokenHash, 'now' => Timestamp::utc($now)],
        );

        return $id === null ? null : (int) $id;
    }

    public function purgeExpired(DateTimeImmutable $before): int
    {
        return $this->database->execute(
            'DELETE FROM content_preview_tokens WHERE expires_at <= :before',
            ['before' => Timestamp::utc($before)],
        );
    }
}
