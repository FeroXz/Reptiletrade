<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

interface BreederProfileRepository
{
    public function findByUser(int $userId): ?BreederProfile;

    public function findBySlug(string $slug): ?BreederProfile;

    public function save(BreederProfile $profile): void;

    /**
     * Prueft, ob der Slug schon vergeben ist — ausser an das eigene Profil.
     */
    public function slugTaken(string $slug, ?int $exceptUserId = null): bool;

    public function delete(int $userId): void;

    /**
     * Kennzahlen der oeffentlichen Profilseite.
     *
     * @return array{aktive_anzeigen: int, bewertungen: int, schnitt: ?float}
     */
    public function statistics(int $userId): array;
}
