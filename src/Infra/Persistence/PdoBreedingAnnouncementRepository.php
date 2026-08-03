<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Breeding\AnnouncementStatus;
use Reptilienmarkt\Domain\Breeding\BreedingAnnouncement;
use Reptilienmarkt\Domain\Breeding\BreedingAnnouncementRepository;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoBreedingAnnouncementRepository implements BreedingAnnouncementRepository
{
    private const string COLUMNS = 'id, user_id, species_id, title, description, expected_at, morph_note, '
        . 'status, created_at';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?BreedingAnnouncement
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM breeding_announcements WHERE id = :id',
            ['id' => $id],
        );

        return $row === null ? null : $this->map($row);
    }

    public function forUser(int $userId): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM breeding_announcements WHERE user_id = :user
             ORDER BY created_at DESC',
            ['user' => $userId],
        );

        return array_map($this->map(...), $rows);
    }

    public function publicForSpecies(int $speciesId, int $limit = 10): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . " FROM breeding_announcements
              WHERE species_id = :species AND status = 'veroeffentlicht'
              ORDER BY expected_at IS NULL, expected_at ASC LIMIT :limit",
            ['species' => $speciesId, 'limit' => $limit],
        );

        return array_map($this->map(...), $rows);
    }

    public function save(BreedingAnnouncement $announcement): int
    {
        $now = Timestamp::now();

        if ($announcement->id === null) {
            $this->database->execute(
                'INSERT INTO breeding_announcements
                    (user_id, species_id, title, description, expected_at, morph_note, status, created_at, updated_at)
                 VALUES (:user, :species, :title, :description, :expected, :morph, :status, :now, :now)',
                [
                    'user' => $announcement->userId,
                    'species' => $announcement->speciesId,
                    'title' => $announcement->title,
                    'description' => $announcement->description,
                    'expected' => Timestamp::utcOrNull($announcement->expectedAt),
                    'morph' => $announcement->morphNote,
                    'status' => $announcement->status->value,
                    'now' => $now,
                ],
            );

            return $this->database->lastInsertId();
        }

        $this->database->execute(
            'UPDATE breeding_announcements SET species_id = :species, title = :title, description = :description,
                                               expected_at = :expected, morph_note = :morph, status = :status,
                                               updated_at = :now
              WHERE id = :id',
            [
                'species' => $announcement->speciesId,
                'title' => $announcement->title,
                'description' => $announcement->description,
                'expected' => Timestamp::utcOrNull($announcement->expectedAt),
                'morph' => $announcement->morphNote,
                'status' => $announcement->status->value,
                'now' => $now,
                'id' => $announcement->id,
            ],
        );

        return $announcement->id;
    }

    public function delete(int $id): void
    {
        $this->database->execute('DELETE FROM breeding_announcements WHERE id = :id', ['id' => $id]);
    }

    public function overdue(DateTimeImmutable $moment, int $limit = 100): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . " FROM breeding_announcements
              WHERE status = 'veroeffentlicht' AND expected_at IS NOT NULL AND expected_at < :moment
              ORDER BY expected_at ASC LIMIT :limit",
            ['moment' => Timestamp::utc($moment), 'limit' => $limit],
        );

        return array_map($this->map(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): BreedingAnnouncement
    {
        return new BreedingAnnouncement(
            (int) $row['id'],
            (int) $row['user_id'],
            (int) $row['species_id'],
            (string) $row['title'],
            \is_string($row['description']) ? $row['description'] : null,
            Timestamp::parse(\is_string($row['expected_at']) ? $row['expected_at'] : null),
            \is_string($row['morph_note']) ? $row['morph_note'] : null,
            AnnouncementStatus::from((string) $row['status']),
            Timestamp::parse(\is_string($row['created_at']) ? $row['created_at'] : null),
        );
    }
}
