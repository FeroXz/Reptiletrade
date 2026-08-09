<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Support\Clock;

/**
 * Aufrufe und Anfragen je Anbieter.
 *
 * Gelesen wird ueber Rohabfragen statt ueber die Repositories: Die Statistik
 * fragt nach Summen und Gruppierungen, nicht nach Entitaeten — sie dafuer erst
 * zu Objekten zu machen, waere Arbeit fuer nichts.
 *
 * Die Zeitreihe wird lueckenlos zurueckgegeben, auch fuer Tage ohne Aufruf.
 * Ein Diagramm, das fehlende Tage einfach weglaesst, staucht die Zeitachse und
 * zeigt einen Verlauf, den es nicht gab.
 */
final readonly class SellerStatsService
{
    public const int DEFAULT_DAYS = 30;

    public function __construct(
        private Database $database,
        private Clock $clock,
    ) {}

    public function forUser(int $userId, int $days = self::DEFAULT_DAYS): SellerStats
    {
        $days = max(7, min(365, $days));
        $seit = $this->clock->now()->modify(\sprintf('-%d days', $days - 1))->format('Y-m-d');

        return new SellerStats(
            $this->scalar(
                'SELECT COALESCE(SUM(v.views), 0) FROM listing_views v
                   JOIN listings l ON l.id = v.listing_id
                  WHERE l.user_id = :user AND v.day >= :seit',
                ['user' => $userId, 'seit' => $seit],
            ),
            $this->scalar(
                'SELECT COUNT(*) FROM conversations c
                   JOIN listings l ON l.id = c.listing_id
                  WHERE l.user_id = :user AND c.created_at >= :seit',
                ['user' => $userId, 'seit' => $seit],
            ),
            $this->scalar(
                "SELECT COUNT(*) FROM listings WHERE user_id = :user AND status IN ('aktiv','reserviert')",
                ['user' => $userId],
            ),
            $this->series(
                'SELECT v.day AS tag, SUM(v.views) AS anzahl FROM listing_views v
                   JOIN listings l ON l.id = v.listing_id
                  WHERE l.user_id = :user AND v.day >= :seit
                  GROUP BY v.day',
                ['user' => $userId, 'seit' => $seit],
                $days,
            ),
            $this->series(
                "SELECT substr(c.created_at, 1, 10) AS tag, COUNT(*) AS anzahl FROM conversations c
                   JOIN listings l ON l.id = c.listing_id
                  WHERE l.user_id = :user AND c.created_at >= :seit
                  GROUP BY tag",
                ['user' => $userId, 'seit' => $seit],
                $days,
            ),
            $this->listings($userId, $seit),
            $days,
        );
    }

    /**
     * @return list<ListingStatsRow>
     */
    private function listings(int $userId, string $seit): array
    {
        $rows = $this->database->select(
            'SELECT l.id, l.title, l.status,
                    (SELECT COALESCE(SUM(v.views), 0) FROM listing_views v
                      WHERE v.listing_id = l.id AND v.day >= :seit) AS aufrufe,
                    (SELECT COUNT(*) FROM conversations c
                      WHERE c.listing_id = l.id AND c.created_at >= :seit) AS anfragen,
                    -- Ohne Zeitfenster: Eine Merkung gilt, bis sie
                    -- zurueckgenommen wird, und nicht nur im Berichtszeitraum.
                    (SELECT COUNT(*) FROM listing_favorites f
                      WHERE f.listing_id = l.id) AS merkungen
               FROM listings l
              WHERE l.user_id = :user AND l.status <> \'entwurf\'
              ORDER BY aufrufe DESC, l.id DESC
              LIMIT 50',
            ['user' => $userId, 'seit' => $seit],
        );

        return array_map(
            static fn(array $row): ListingStatsRow => new ListingStatsRow(
                (int) $row['id'],
                (string) $row['title'],
                ListingStatus::from((string) $row['status']),
                (int) $row['aufrufe'],
                (int) $row['anfragen'],
                (int) $row['merkungen'],
            ),
            $rows,
        );
    }

    /**
     * @param array<string, scalar> $parameters
     *
     * @return array<string, int>
     */
    private function series(string $sql, array $parameters, int $days): array
    {
        $gemessen = [];

        foreach ($this->database->select($sql, $parameters) as $row) {
            $gemessen[(string) $row['tag']] = (int) $row['anzahl'];
        }

        $reihe = [];

        for ($i = $days - 1; $i >= 0; --$i) {
            $tag = $this->clock->now()->modify(\sprintf('-%d days', $i))->format('Y-m-d');
            $reihe[$tag] = $gemessen[$tag] ?? 0;
        }

        return $reihe;
    }

    /**
     * @param array<string, scalar> $parameters
     */
    private function scalar(string $sql, array $parameters): int
    {
        $value = $this->database->scalar($sql, $parameters);

        return (int) (is_numeric($value) ? $value : 0);
    }
}
