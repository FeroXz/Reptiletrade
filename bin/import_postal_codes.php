<?php

declare(strict_types=1);

use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Geo\PostalCodeRepository;
use Reptilienmarkt\Infra\Geo\PostalCodeImporter;
use Reptilienmarkt\Support\Container;

if (\PHP_SAPI !== 'cli') {
    exit("bin/import_postal_codes.php laeuft nur auf der Kommandozeile.\n");
}

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

/** @var list<string> $argv */
$argv = $argv ?? [];

$option = static function (string $name) use ($argv): ?string {
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--' . $name . '=')) {
            return substr($argument, strlen($name) + 3);
        }
    }

    return null;
};

if (in_array('--help', $argv, true)) {
    echo <<<'TEXT'
        Verwendung: php bin/import_postal_codes.php [optionen]

        Ohne Optionen wird der mitgelieferte Datensatz data/postal_codes.tsv.gz eingespielt.

        Optionen:
          --geonames=<datei>   GeoNames-Datei (DE.txt, AT.txt, CH.txt) statt des Datensatzes
          --country=DE|AT|CH   Beim GeoNames-Import auf ein Land begrenzen

        Der GeoNames-Import ersetzt die interpolierten Zentroide durch die exakten Werte.
        Bezug: https://download.geonames.org/export/zip/ (CC BY 4.0)

        TEXT;
    exit(0);
}

$repository = $container->get(PostalCodeRepository::class);
$importer = new PostalCodeImporter($repository);

$geonames = $option('geonames');
$countryOption = $option('country');
$country = $countryOption === null ? null : Country::tryFrom(strtoupper($countryOption));

if ($countryOption !== null && $country === null) {
    fwrite(\STDERR, sprintf("Unbekanntes Land: %s (erlaubt: DE, AT, CH)\n", $countryOption));
    exit(1);
}

$start = microtime(true);

try {
    if ($geonames !== null) {
        $written = $importer->importGeonames($geonames, $country);
        $sourceLabel = $geonames;
    } else {
        $file = $container->get('paths.data') . '/postal_codes.tsv.gz';
        $written = $importer->importBundled($file);
        $sourceLabel = $file;
    }
} catch (Throwable $exception) {
    fwrite(\STDERR, 'Import fehlgeschlagen: ' . $exception->getMessage() . "\n");
    exit(1);
}

printf(
    "%d Postleitzahlen aus %s eingespielt (%.1f s).\n",
    $written,
    $sourceLabel,
    microtime(true) - $start,
);

foreach (Country::cases() as $case) {
    printf("  %s: %d\n", $case->value, $repository->count($case));
}

exit(0);
