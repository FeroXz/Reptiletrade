<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

interface UserDocumentRepository
{
    public function findById(int $id): ?UserDocument;

    /**
     * @return list<UserDocument>
     */
    public function forUser(int $userId): array;

    public function findByType(int $userId, UserDocumentType $type): ?UserDocument;

    public function save(UserDocument $document): int;

    /**
     * Offene Nachweise fuer die Moderationsliste, aelteste zuerst.
     *
     * @return list<UserDocument>
     */
    public function pending(int $limit = 50): array;

    public function delete(int $id): void;
}
