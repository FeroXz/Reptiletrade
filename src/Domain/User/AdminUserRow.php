<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

use DateTimeImmutable;

/**
 * Eine Zeile der Nutzerliste in der Verwaltung — mit den Zahlen, nach denen
 * dort tatsaechlich entschieden wird.
 */
final readonly class AdminUserRow
{
    public function __construct(
        public int $id,
        public string $email,
        public string $displayName,
        public Role $role,
        public UserStatus $status,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastLoginAt,
        public int $listings,
        public int $openReports,
        public ?BanState $ban = null,
    ) {}

    public function isBanned(): bool
    {
        return $this->status === UserStatus::Gesperrt;
    }
}
