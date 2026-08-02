<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Geo;

use Generator;
use Reptilienmarkt\Domain\Geo\Coordinates;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Geo\PostalCode;
use Reptilienmarkt\Domain\Geo\PostalCodeRepository;
use Reptilienmarkt\Domain\Geo\PostalCodeSource;
use RuntimeException;

/**
 * Liest PLZ-Daten aus lokalen Dateien. Es gibt bewusst keinen Laufzeit-API-Call:
 * Der Datensatz liegt gepackt im Repository und wird beim Seed eingespielt.
 */
final readonly class PostalCodeImporter
{
    public function __construct(private PostalCodeRepository $repository) {}

    /**
     * Liest die mitgelieferte TSV-Datei (gzip) mit den Spalten
     * country, postal_code, place_name, admin1, lat, lng, source.
     */
    public function importBundled(string $file): int
    {
        if (!is_file($file)) {
            throw new RuntimeException(\sprintf('PLZ-Datensatz nicht gefunden: %s', $file));
        }

        return $this->repository->upsertMany($this->readBundled($file));
    }

    /**
     * Liest eine GeoNames-Postleitzahlendatei (DE.txt, AT.txt, CH.txt) im
     * Originalformat. Damit lassen sich die interpolierten Zentroide des
     * mitgelieferten Datensatzes durch die exakten Werte ersetzen.
     */
    public function importGeonames(string $file, ?Country $country = null): int
    {
        if (!is_file($file)) {
            throw new RuntimeException(\sprintf('GeoNames-Datei nicht gefunden: %s', $file));
        }

        return $this->repository->upsertMany($this->readGeonames($file, $country));
    }

    /**
     * @return Generator<int, PostalCode>
     */
    private function readBundled(string $file): Generator
    {
        $handle = gzopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException(\sprintf('PLZ-Datensatz nicht lesbar: %s', $file));
        }

        try {
            $header = gzgets($handle);
            if ($header === false) {
                throw new RuntimeException('PLZ-Datensatz ist leer.');
            }

            while (($line = gzgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }

                $columns = explode("\t", $line);
                if (\count($columns) < 7) {
                    continue;
                }

                $country = Country::tryFrom($columns[0]);
                if ($country === null) {
                    continue;
                }

                yield new PostalCode(
                    $country,
                    $columns[1],
                    $columns[2],
                    $columns[3] === '' ? null : $columns[3],
                    new Coordinates((float) $columns[4], (float) $columns[5]),
                    PostalCodeSource::tryFrom($columns[6]) ?? PostalCodeSource::Interpolated,
                );
            }
        } finally {
            gzclose($handle);
        }
    }

    /**
     * GeoNames-Spalten: country_code, postal_code, place_name, admin_name1, admin_code1,
     * admin_name2, admin_code2, admin_name3, admin_code3, latitude, longitude, accuracy.
     *
     * @return Generator<int, PostalCode>
     */
    private function readGeonames(string $file, ?Country $only): Generator
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException(\sprintf('GeoNames-Datei nicht lesbar: %s', $file));
        }

        $seen = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $columns = explode("\t", rtrim($line, "\r\n"));
                if (\count($columns) < 11) {
                    continue;
                }

                $country = Country::tryFrom($columns[0]);
                if ($country === null || ($only !== null && $country !== $only)) {
                    continue;
                }

                // GeoNames fuehrt je Ortsteil eine Zeile; die erste je PLZ gewinnt.
                $key = $country->value . '|' . $columns[1];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                if ($columns[9] === '' || $columns[10] === '') {
                    continue;
                }

                yield new PostalCode(
                    $country,
                    $columns[1],
                    $columns[2],
                    $columns[3] === '' ? null : $columns[3],
                    new Coordinates((float) $columns[9], (float) $columns[10]),
                    PostalCodeSource::Geonames,
                );
            }
        } finally {
            fclose($handle);
        }
    }
}
