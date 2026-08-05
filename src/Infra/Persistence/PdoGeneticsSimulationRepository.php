<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Genetics\GeneticsSimulationRepository;
use Reptilienmarkt\Domain\Genetics\SimulationResult;
use Reptilienmarkt\Domain\Genetics\StoredSimulation;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoGeneticsSimulationRepository implements GeneticsSimulationRepository
{
    private const string COLUMNS = 'id, user_id, species_id, listing_a_id, listing_b_id, titel, result_json, created_at';

    public function __construct(private Database $database) {}

    public function save(StoredSimulation $simulation): int
    {
        $this->database->execute(
            'INSERT INTO genetics_simulations
                (user_id, species_id, listing_a_id, listing_b_id, titel, result_json, created_at)
             VALUES (:user_id, :species_id, :listing_a, :listing_b, :titel, :result, :created_at)',
            [
                'user_id' => $simulation->userId,
                'species_id' => $simulation->speciesId,
                'listing_a' => $simulation->listingAId,
                'listing_b' => $simulation->listingBId,
                'titel' => $simulation->title,
                'result' => json_encode($simulation->result->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'created_at' => Timestamp::utc($simulation->createdAt ?? $simulation->result->createdAt()),
            ],
        );

        return $this->database->lastInsertId();
    }

    public function findById(int $id): ?StoredSimulation
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM genetics_simulations WHERE id = :id',
            ['id' => $id],
        );

        return $row === null ? null : $this->map($row);
    }

    public function forUser(int $userId, int $limit = 50): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM genetics_simulations WHERE user_id = :user
              ORDER BY created_at DESC, id DESC LIMIT :limit',
            ['user' => $userId, 'limit' => $limit],
        );

        return array_map($this->map(...), $rows);
    }

    public function countForUser(int $userId): int
    {
        $count = $this->database->scalar(
            'SELECT COUNT(*) FROM genetics_simulations WHERE user_id = :user',
            ['user' => $userId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function deleteById(int $id): void
    {
        $this->database->execute('DELETE FROM genetics_simulations WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): StoredSimulation
    {
        /** @var mixed $decoded */
        $decoded = json_decode((string) $row['result_json'], true);

        return new StoredSimulation(
            (int) $row['id'],
            (int) $row['user_id'],
            (int) $row['species_id'],
            (string) $row['titel'],
            SimulationResult::fromArray(\is_array($decoded) ? $decoded : []),
            $row['listing_a_id'] === null ? null : (int) $row['listing_a_id'],
            $row['listing_b_id'] === null ? null : (int) $row['listing_b_id'],
            new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
