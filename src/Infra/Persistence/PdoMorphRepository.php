<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use Reptilienmarkt\Domain\Species\Inheritance;
use Reptilienmarkt\Domain\Species\Morph;
use Reptilienmarkt\Domain\Species\MorphRepository;

final readonly class PdoMorphRepository implements MorphRepository
{
    private const string COLUMNS = 'id, species_id, name, aliases, inheritance, allele_group, is_lethal_combo, description';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?Morph
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM morphs WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->map($row);
    }

    public function forSpecies(int $speciesId): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM morphs WHERE species_id = :species_id ORDER BY name',
            ['species_id' => $speciesId],
        );

        return array_map(fn(array $row): Morph => $this->map($row), $rows);
    }

    public function findByName(int $speciesId, string $name): ?Morph
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM morphs WHERE species_id = :species_id AND name = :name',
            ['species_id' => $speciesId, 'name' => $name],
        );

        return $row === null ? null : $this->map($row);
    }

    public function save(Morph $morph): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->database->execute(
            'INSERT INTO morphs (species_id, name, aliases, inheritance, allele_group, is_lethal_combo, description, created_at, updated_at)
             VALUES (:species_id, :name, :aliases, :inheritance, :allele_group, :is_lethal_combo, :description, :updated_at, :updated_at)
             ON CONFLICT(species_id, name) DO UPDATE SET
                aliases = excluded.aliases,
                inheritance = excluded.inheritance,
                allele_group = excluded.allele_group,
                is_lethal_combo = excluded.is_lethal_combo,
                description = excluded.description,
                updated_at = excluded.updated_at',
            [
                'species_id' => $morph->speciesId,
                'name' => $morph->name,
                'aliases' => json_encode(array_values($morph->aliases), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'inheritance' => $morph->inheritance->value,
                'allele_group' => $morph->alleleGroup,
                'is_lethal_combo' => $morph->isLethalCombo ? 1 : 0,
                'description' => $morph->description,
                'updated_at' => $now,
            ],
        );

        $id = $this->database->scalar(
            'SELECT id FROM morphs WHERE species_id = :species_id AND name = :name',
            ['species_id' => $morph->speciesId, 'name' => $morph->name],
        );

        return (int) (is_numeric($id) ? $id : 0);
    }

    public function deleteById(int $id): void
    {
        $this->database->execute('DELETE FROM morphs WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Morph
    {
        /** @var mixed $decoded */
        $decoded = json_decode((string) $row['aliases'], true);
        $aliases = [];
        if (\is_array($decoded)) {
            foreach ($decoded as $alias) {
                if (\is_string($alias)) {
                    $aliases[] = $alias;
                }
            }
        }

        return new Morph(
            (int) $row['id'],
            (int) $row['species_id'],
            (string) $row['name'],
            Inheritance::from((string) $row['inheritance']),
            $aliases,
            $row['allele_group'] === null ? null : (string) $row['allele_group'],
            (bool) $row['is_lethal_combo'],
            $row['description'] === null ? null : (string) $row['description'],
        );
    }
}
