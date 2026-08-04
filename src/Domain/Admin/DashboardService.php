<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Admin;

use Reptilienmarkt\Domain\Job\JobRepository;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Legal\LegalTextReview;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Timestamp;

/**
 * Kennzahlen des Admin-Dashboards.
 *
 * Bewusst ohne Zwischenspeicher: Die Abfragen laufen ueber Indizes und ein
 * Dashboard, das veraltete Zahlen zeigt, ist schlimmer als eines, das eine
 * halbe Sekunde braucht.
 */
final readonly class DashboardService
{
    public function __construct(
        private Database $database,
        private JobRepository $jobs,
        private LegalTextReview $legalReview,
        private Clock $clock,
    ) {}

    public function stats(): DashboardStats
    {
        return new DashboardStats(
            $this->listingsPerDay(14),
            $this->topSpecies(10),
            $this->count("SELECT COUNT(*) FROM reports WHERE status IN ('offen','in_pruefung')"),
            $this->count("SELECT COUNT(*) FROM listings WHERE status = 'pruefung'"),
            $this->count("SELECT COUNT(*) FROM user_documents WHERE status = 'offen'"),
            $this->count('SELECT COUNT(*) FROM messages WHERE flagged_reason IS NOT NULL'),
            $this->count("SELECT COUNT(*) FROM listings WHERE status IN ('aktiv','reserviert')"),
            $this->count("SELECT COUNT(*) FROM listings WHERE status = 'pausiert'"),
            $this->count("SELECT COUNT(*) FROM users WHERE status = 'aktiv'"),
            $this->countSince('users', 'created_at', 7),
            $this->legalReview->stale(),
            $this->jobs->countsByStatus(),
        );
    }

    /**
     * Anzeigen je Tag — die Zeitreihe fuer den Verlauf.
     *
     * @return array<string, int> Datum => Anzahl
     */
    public function listingsPerDay(int $days = 14): array
    {
        $seit = $this->clock->now()->modify(\sprintf('-%d days', $days));

        $rows = $this->database->select(
            "SELECT substr(created_at, 1, 10) AS tag, COUNT(*) AS anzahl
               FROM listings
              WHERE created_at >= :seit AND status <> 'entwurf'
              GROUP BY tag
              ORDER BY tag",
            ['seit' => Timestamp::utc($seit)],
        );

        // Luecken auffuellen: Ein Tag ohne Anzeigen ist eine Aussage und darf
        // nicht einfach fehlen.
        $reihe = [];
        for ($i = $days; $i >= 0; --$i) {
            $reihe[$this->clock->now()->modify(\sprintf('-%d days', $i))->format('Y-m-d')] = 0;
        }

        foreach ($rows as $row) {
            $tag = (string) $row['tag'];
            if (\array_key_exists($tag, $reihe)) {
                $reihe[$tag] = (int) $row['anzahl'];
            }
        }

        return $reihe;
    }

    /**
     * @return list<array{art: string, anzahl: int}>
     */
    public function topSpecies(int $limit = 10): array
    {
        $rows = $this->database->select(
            "SELECT s.common_name_de AS art, COUNT(*) AS anzahl
               FROM listings l
               JOIN species s ON s.id = l.species_id
              WHERE l.status IN ('aktiv','reserviert')
              GROUP BY s.id
              ORDER BY anzahl DESC, art ASC
              LIMIT :limit",
            ['limit' => $limit],
        );

        return array_map(
            static fn(array $row): array => ['art' => (string) $row['art'], 'anzahl' => (int) $row['anzahl']],
            $rows,
        );
    }

    private function countSince(string $table, string $column, int $days): int
    {
        return $this->count(
            \sprintf('SELECT COUNT(*) FROM %s WHERE %s >= :seit', $table, $column),
            ['seit' => Timestamp::utc($this->clock->now()->modify(\sprintf('-%d days', $days)))],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function count(string $sql, array $parameters = []): int
    {
        $value = $this->database->scalar($sql, $parameters);

        return (int) (is_numeric($value) ? $value : 0);
    }
}
