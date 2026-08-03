<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Breeding;

use DateTimeImmutable;

interface BreedingAnnouncementRepository
{
    public function findById(int $id): ?BreedingAnnouncement;

    /**
     * @return list<BreedingAnnouncement>
     */
    public function forUser(int $userId): array;

    /**
     * Oeffentliche Ankuendigungen zu einer Art — fuer das Artenprofil.
     *
     * @return list<BreedingAnnouncement>
     */
    public function publicForSpecies(int $speciesId, int $limit = 10): array;

    public function save(BreedingAnnouncement $announcement): int;

    public function delete(int $id): void;

    /**
     * @return list<BreedingAnnouncement>
     */
    public function overdue(DateTimeImmutable $moment, int $limit = 100): array;
}
