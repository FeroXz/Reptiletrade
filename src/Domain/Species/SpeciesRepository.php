<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Species;

/**
 * Persistenzgrenze fuer den Artenstamm. Enthaelt bewusst kein SQL und keine
 * Kriterien-Strings, damit ein PostgreSQL-Wechsel die Domain nicht beruehrt.
 */
interface SpeciesRepository
{
    public function findById(int $id): ?Species;

    public function findBySlug(string $slug): ?Species;

    /**
     * Die Marktpfade sprechen die Nutzersprache: /markt/bartagame/
     */
    public function findByCommonSlug(string $slug): ?Species;

    public function findByScientificName(string $scientificName): ?Species;

    /**
     * @return list<Species>
     */
    public function all(): array;

    /**
     * Freitextsuche fuer das Art-Autocomplete (wissenschaftlicher und deutscher Name).
     *
     * @return list<Species>
     */
    public function search(string $term, int $limit = 20): array;

    /**
     * Legt an oder aktualisiert anhand des wissenschaftlichen Namens und liefert die ID.
     */
    public function save(Species $species): int;

    public function deleteById(int $id): void;
}
