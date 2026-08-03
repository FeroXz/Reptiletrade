<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use Reptilienmarkt\Domain\User\BreederProfile;
use Reptilienmarkt\Domain\User\BreederProfileRepository;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoBreederProfileRepository implements BreederProfileRepository
{
    private const string COLUMNS = 'user_id, slug, headline, description, focus_species_json, breeding_since, '
        . 'website, is_public, created_at';

    public function __construct(private Database $database) {}

    public function findByUser(int $userId): ?BreederProfile
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM breeder_profiles WHERE user_id = :id',
            ['id' => $userId],
        );

        return $row === null ? null : $this->map($row);
    }

    public function findBySlug(string $slug): ?BreederProfile
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM breeder_profiles WHERE slug = :slug',
            ['slug' => $slug],
        );

        return $row === null ? null : $this->map($row);
    }

    public function save(BreederProfile $profile): void
    {
        $this->database->execute(
            <<<'SQL'
                INSERT INTO breeder_profiles
                    (user_id, slug, headline, description, focus_species_json, breeding_since, website, is_public, created_at, updated_at)
                VALUES (:user_id, :slug, :headline, :description, :focus, :since, :website, :public, :now, :now)
                ON CONFLICT(user_id) DO UPDATE SET
                    slug = excluded.slug,
                    headline = excluded.headline,
                    description = excluded.description,
                    focus_species_json = excluded.focus_species_json,
                    breeding_since = excluded.breeding_since,
                    website = excluded.website,
                    is_public = excluded.is_public,
                    updated_at = excluded.updated_at
                SQL,
            [
                'user_id' => $profile->userId,
                'slug' => $profile->slug,
                'headline' => $profile->headline,
                'description' => $profile->description,
                'focus' => json_encode(array_values($profile->focusSpeciesIds), \JSON_THROW_ON_ERROR),
                'since' => $profile->breedingSince,
                'website' => $profile->website,
                'public' => $profile->isPublic ? 1 : 0,
                'now' => Timestamp::now(),
            ],
        );
    }

    public function slugTaken(string $slug, ?int $exceptUserId = null): bool
    {
        $value = $this->database->scalar(
            'SELECT COUNT(*) FROM breeder_profiles WHERE slug = :slug AND (:except IS NULL OR user_id <> :except)',
            ['slug' => $slug, 'except' => $exceptUserId],
        );

        return (int) (is_numeric($value) ? $value : 0) > 0;
    }

    public function delete(int $userId): void
    {
        $this->database->execute('DELETE FROM breeder_profiles WHERE user_id = :id', ['id' => $userId]);
    }

    public function statistics(int $userId): array
    {
        $row = $this->database->selectOne(
            <<<'SQL'
                SELECT
                    (SELECT COUNT(*) FROM listings
                      WHERE user_id = :user AND status IN ('aktiv','reserviert')) AS aktive_anzeigen,
                    (SELECT COUNT(*) FROM reviews WHERE to_user_id = :user) AS bewertungen,
                    (SELECT AVG(rating) FROM reviews WHERE to_user_id = :user) AS schnitt
                SQL,
            ['user' => $userId],
        );

        $average = $row['schnitt'] ?? null;

        return [
            'aktive_anzeigen' => (int) ($row['aktive_anzeigen'] ?? 0),
            'bewertungen' => (int) ($row['bewertungen'] ?? 0),
            'schnitt' => is_numeric($average) ? (float) $average : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): BreederProfile
    {
        /** @var mixed $focus */
        $focus = json_decode((string) $row['focus_species_json'], true);

        $ids = [];
        if (\is_array($focus)) {
            foreach ($focus as $entry) {
                if (is_numeric($entry)) {
                    $ids[] = (int) $entry;
                }
            }
        }

        return new BreederProfile(
            (int) $row['user_id'],
            (string) $row['slug'],
            \is_string($row['headline']) ? $row['headline'] : null,
            \is_string($row['description']) ? $row['description'] : null,
            $ids,
            $row['breeding_since'] === null ? null : (int) $row['breeding_since'],
            \is_string($row['website']) ? $row['website'] : null,
            (bool) $row['is_public'],
            Timestamp::parse(\is_string($row['created_at']) ? $row['created_at'] : null),
        );
    }
}
