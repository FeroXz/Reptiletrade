<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use Reptilienmarkt\Domain\Geo\BoundingBox;
use Reptilienmarkt\Domain\Geo\Coordinates;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Geo\PostalCode;
use Reptilienmarkt\Domain\Geo\PostalCodeRepository;
use Reptilienmarkt\Domain\Geo\PostalCodeSource;

final readonly class PdoPostalCodeRepository implements PostalCodeRepository
{
    private const string COLUMNS = 'country, postal_code, place_name, admin1, lat, lng, source';

    public function __construct(private Database $database) {}

    public function find(Country $country, string $postalCode): ?PostalCode
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM postal_codes WHERE country = :country AND postal_code = :postal_code',
            ['country' => $country->value, 'postal_code' => $postalCode],
        );

        return $row === null ? null : $this->map($row);
    }

    public function withinBoundingBox(BoundingBox $box, ?Country $country = null): array
    {
        // Der Index idx_postal_codes_bbox(lat, lng) traegt den Vorfilter; die exakte
        // Distanz wird erst auf diesem Ergebnis berechnet.
        $sql = 'SELECT ' . self::COLUMNS . ' FROM postal_codes
                WHERE lat BETWEEN :min_lat AND :max_lat
                  AND lng BETWEEN :min_lng AND :max_lng';

        $parameters = [
            'min_lat' => $box->minLatitude,
            'max_lat' => $box->maxLatitude,
            'min_lng' => $box->minLongitude,
            'max_lng' => $box->maxLongitude,
        ];

        if ($country !== null) {
            $sql .= ' AND country = :country';
            $parameters['country'] = $country->value;
        }

        return array_map(fn(array $row): PostalCode => $this->map($row), $this->database->select($sql, $parameters));
    }

    public function search(string $term, ?Country $country = null, int $limit = 20): array
    {
        $term = trim($term);
        $pattern = str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

        $sql = 'SELECT ' . self::COLUMNS . ' FROM postal_codes
                WHERE (postal_code LIKE :pattern ESCAPE \'\\\' OR place_name LIKE :pattern ESCAPE \'\\\')';

        $parameters = ['pattern' => $pattern];

        if ($country !== null) {
            $sql .= ' AND country = :country';
            $parameters['country'] = $country->value;
        }

        $sql .= ' ORDER BY postal_code LIMIT :limit';
        $parameters['limit'] = $limit;

        return array_map(fn(array $row): PostalCode => $this->map($row), $this->database->select($sql, $parameters));
    }

    public function count(?Country $country = null): int
    {
        $sql = 'SELECT COUNT(*) FROM postal_codes';
        $parameters = [];

        if ($country !== null) {
            $sql .= ' WHERE country = :country';
            $parameters['country'] = $country->value;
        }

        $value = $this->database->scalar($sql, $parameters);

        return (int) (is_numeric($value) ? $value : 0);
    }

    public function upsertMany(iterable $postalCodes): int
    {
        $statement = $this->database->pdo()->prepare(
            'INSERT INTO postal_codes (country, postal_code, place_name, admin1, lat, lng, source)
             VALUES (:country, :postal_code, :place_name, :admin1, :lat, :lng, :source)
             ON CONFLICT(country, postal_code) DO UPDATE SET
                place_name = excluded.place_name,
                admin1 = excluded.admin1,
                lat = excluded.lat,
                lng = excluded.lng,
                source = excluded.source',
        );

        $written = 0;
        $this->database->transaction(static function () use ($postalCodes, $statement, &$written): void {
            foreach ($postalCodes as $postalCode) {
                $statement->execute([
                    'country' => $postalCode->country->value,
                    'postal_code' => $postalCode->postalCode,
                    'place_name' => $postalCode->placeName,
                    'admin1' => $postalCode->admin1,
                    'lat' => $postalCode->coordinates->latitude,
                    'lng' => $postalCode->coordinates->longitude,
                    'source' => $postalCode->source->value,
                ]);
                ++$written;
            }
        });

        return $written;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): PostalCode
    {
        return new PostalCode(
            Country::from((string) $row['country']),
            (string) $row['postal_code'],
            (string) $row['place_name'],
            $row['admin1'] === null ? null : (string) $row['admin1'],
            new Coordinates((float) $row['lat'], (float) $row['lng']),
            PostalCodeSource::from((string) $row['source']),
        );
    }
}
