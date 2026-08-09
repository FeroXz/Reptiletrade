<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use DateTimeImmutable;

interface ContentEditorRepository
{
    public function isEditor(int $userId): bool;

    public function grant(int $userId, DateTimeImmutable $moment, ?int $grantedBy): void;

    public function revoke(int $userId): void;

    /**
     * Alle Redakteure mit ihrem Erteilungsdatum — fuer die Verwaltung.
     *
     * @return list<array{user_id: int, email: string, display_name: string, granted_at: string}>
     */
    public function all(): array;

    public function count(): int;
}
