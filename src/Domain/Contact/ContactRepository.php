<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Contact;

interface ContactRepository
{
    public function save(ContactMessage $message, ?string $ipAddress): int;

    public function find(int $id): ?ContactMessage;

    /**
     * @return list<ContactMessage>
     */
    public function recent(bool $onlyOpen = true, int $limit = 100): array;

    public function markHandled(int $id, int $adminId, ?string $note): void;

    public function openCount(): int;
}
