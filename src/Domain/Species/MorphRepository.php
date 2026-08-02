<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Species;

interface MorphRepository
{
    public function findById(int $id): ?Morph;

    /**
     * @return list<Morph>
     */
    public function forSpecies(int $speciesId): array;

    public function findByName(int $speciesId, string $name): ?Morph;

    /**
     * Legt an oder aktualisiert anhand (species_id, name) und liefert die ID.
     */
    public function save(Morph $morph): int;

    public function deleteById(int $id): void;
}
