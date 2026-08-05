<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

/**
 * Persistenzgrenze fuer gespeicherte Simulationen. Wie ueberall in der Domain
 * ohne SQL: Der Bericht ist ein Dokument, kein Datenbestand, und liegt deshalb
 * als JSON — was die Domain aber nichts angeht.
 */
interface GeneticsSimulationRepository
{
    public function save(StoredSimulation $simulation): int;

    public function findById(int $id): ?StoredSimulation;

    /**
     * @return list<StoredSimulation>
     */
    public function forUser(int $userId, int $limit = 50): array;

    public function countForUser(int $userId): int;

    public function deleteById(int $id): void;
}
