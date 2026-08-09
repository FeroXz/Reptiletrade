<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use Reptilienmarkt\Domain\Seo\SitemapRepository;
use Reptilienmarkt\Domain\Seo\SitemapUrl;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoSitemapRepository implements SitemapRepository
{
    public function __construct(private Database $database) {}

    public function listings(int $limit = 20000): array
    {
        // Dieselben Status wie ListingStatus::isPubliclyVisible(). Die Liste
        // steht hier ausgeschrieben, weil SQL kein Enum kennt — aendert sich
        // die Sichtbarkeit, muessen beide Stellen angefasst werden, und der
        // Test haelt das fest.
        return $this->collect(
            "SELECT '/anzeige/' || id || '/' AS loc, updated_at AS stand
               FROM listings
              WHERE status IN ('aktiv', 'reserviert')
              ORDER BY id
              LIMIT :limit",
            $limit,
        );
    }

    public function species(int $limit = 5000): array
    {
        return $this->collect(
            "SELECT '/art/' || slug || '/' AS loc, updated_at AS stand
               FROM species
              ORDER BY id
              LIMIT :limit",
            $limit,
        );
    }

    public function breeders(int $limit = 5000): array
    {
        return $this->collect(
            "SELECT '/zuechter/' || p.slug || '/' AS loc, p.updated_at AS stand
               FROM breeder_profiles p
               JOIN users u ON u.id = p.user_id
              WHERE p.is_public = 1 AND u.status = 'aktiv'
              ORDER BY p.user_id
              LIMIT :limit",
            $limit,
        );
    }

    /**
     * @return list<SitemapUrl>
     */
    private function collect(string $sql, int $limit): array
    {
        return array_map(
            static fn(array $row): SitemapUrl => new SitemapUrl(
                (string) $row['loc'],
                Timestamp::parse(\is_string($row['stand']) ? $row['stand'] : null),
            ),
            $this->database->select($sql, ['limit' => $limit]),
        );
    }
}
