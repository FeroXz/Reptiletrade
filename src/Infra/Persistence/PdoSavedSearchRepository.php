<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Search\AlertFrequency;
use Reptilienmarkt\Domain\Search\SavedSearch;
use Reptilienmarkt\Domain\Search\SavedSearchRepository;
use Reptilienmarkt\Domain\Search\SearchCriteriaCodec;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoSavedSearchRepository implements SavedSearchRepository
{
    private const string COLUMNS = 'id, user_id, name, filter_json, alert_frequency, last_alert_at, '
        . 'last_seen_listing_id, created_at';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?SavedSearch
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM saved_searches WHERE id = :id',
            ['id' => $id],
        );

        return $row === null ? null : $this->map($row);
    }

    public function forUser(int $userId): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM saved_searches WHERE user_id = :id ORDER BY id DESC',
            ['id' => $userId],
        );

        return array_map($this->map(...), $rows);
    }

    public function countForUser(int $userId): int
    {
        $value = $this->database->scalar(
            'SELECT COUNT(*) FROM saved_searches WHERE user_id = :id',
            ['id' => $userId],
        );

        return (int) (is_numeric($value) ? $value : 0);
    }

    public function create(SavedSearch $search, DateTimeImmutable $at): int
    {
        $this->database->execute(
            'INSERT INTO saved_searches (user_id, name, filter_json, alert_frequency, last_seen_listing_id, created_at, updated_at)
             VALUES (:user_id, :name, :filter, :frequenz, :seit, :now, :now)',
            [
                'user_id' => $search->userId,
                'name' => $search->name,
                'filter' => json_encode(
                    SearchCriteriaCodec::encode($search->criteria),
                    \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_FORCE_OBJECT,
                ),
                'frequenz' => $search->alertFrequency->value,
                // Der Startpunkt ist der aktuelle Stand: Wer eine Suche merkt,
                // will von jetzt an hoeren, nicht ueber den ganzen Bestand.
                'seit' => $search->lastSeenListingId,
                'now' => Timestamp::utc($at),
            ],
        );

        return $this->database->lastInsertId();
    }

    public function delete(int $id, int $userId): bool
    {
        // Die Konto-ID gehoert in die Bedingung, nicht in eine Pruefung davor:
        // So kann kein Wettlauf dazwischenkommen und keine fremde Suche
        // verschwinden.
        return $this->database->execute(
            'DELETE FROM saved_searches WHERE id = :id AND user_id = :user',
            ['id' => $id, 'user' => $userId],
        ) > 0;
    }

    public function setAlertFrequency(int $id, int $userId, AlertFrequency $frequency, DateTimeImmutable $at): bool
    {
        return $this->database->execute(
            'UPDATE saved_searches SET alert_frequency = :frequenz, updated_at = :now
              WHERE id = :id AND user_id = :user',
            ['frequenz' => $frequency->value, 'now' => Timestamp::utc($at), 'id' => $id, 'user' => $userId],
        ) > 0;
    }

    public function due(AlertFrequency $frequency, int $limit = 500): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::prefixed() . '
               FROM saved_searches s
               JOIN users u ON u.id = s.user_id
              WHERE s.alert_frequency = :frequenz AND u.status = \'aktiv\'
              ORDER BY s.id
              LIMIT :limit',
            ['frequenz' => $frequency->value, 'limit' => $limit],
        );

        return array_map($this->map(...), $rows);
    }

    public function markAlerted(int $id, int $lastSeenListingId, DateTimeImmutable $at): void
    {
        $stamp = Timestamp::utc($at);

        $this->database->execute(
            'UPDATE saved_searches SET last_seen_listing_id = :seit, last_alert_at = :now, updated_at = :now
              WHERE id = :id',
            ['seit' => $lastSeenListingId, 'now' => $stamp, 'id' => $id],
        );
    }

    public function newestListingId(): int
    {
        $value = $this->database->scalar('SELECT MAX(id) FROM listings');

        return (int) (is_numeric($value) ? $value : 0);
    }

    private static function prefixed(): string
    {
        return implode(', ', array_map(
            static fn(string $spalte): string => 's.' . $spalte,
            explode(', ', self::COLUMNS),
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): SavedSearch
    {
        $filter = $row['filter_json'];
        $entpackt = \is_string($filter) ? json_decode($filter, true) : null;

        return new SavedSearch(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['name'],
            SearchCriteriaCodec::decode(\is_array($entpackt) ? $entpackt : []),
            AlertFrequency::from((string) $row['alert_frequency']),
            Timestamp::parse(\is_string($row['last_alert_at']) ? $row['last_alert_at'] : null),
            $row['last_seen_listing_id'] === null ? null : (int) $row['last_seen_listing_id'],
            Timestamp::parse(\is_string($row['created_at']) ? $row['created_at'] : null),
        );
    }
}
