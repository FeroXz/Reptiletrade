<?php

declare(strict_types=1);

use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Geo\PostalCodeRepository;
use Reptilienmarkt\Domain\Species\BnatschgStatus;
use Reptilienmarkt\Domain\Species\CareLevel;
use Reptilienmarkt\Domain\Species\CitesAppendix;
use Reptilienmarkt\Domain\Species\EuAnnex;
use Reptilienmarkt\Domain\Species\Inheritance;
use Reptilienmarkt\Domain\Species\Morph;
use Reptilienmarkt\Domain\Species\MorphRepository;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Infra\Geo\PostalCodeImporter;
use Reptilienmarkt\Support\Container;

if (\PHP_SAPI !== 'cli') {
    exit("bin/seed.php laeuft nur auf der Kommandozeile.\n");
}

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

$dataPath = $container->get('paths.data');
$speciesRepository = $container->get(SpeciesRepository::class);
$morphRepository = $container->get(MorphRepository::class);
$postalCodeRepository = $container->get(PostalCodeRepository::class);

/**
 * @return array<string, mixed>
 */
$readJson = static function (string $file): array {
    $contents = file_get_contents($file);
    if ($contents === false) {
        throw new RuntimeException(sprintf('Datei nicht lesbar: %s', $file));
    }

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

    return $decoded;
};

echo "Artenstamm ...\n";

$speciesFile = $readJson($dataPath . '/species.json');
/** @var list<array<string, mixed>> $speciesRows */
$speciesRows = is_array($speciesFile['arten'] ?? null) ? $speciesFile['arten'] : [];

$speciesIds = [];
foreach ($speciesRows as $row) {
    $cites = $row['cites_appendix'] ?? null;
    $annex = $row['eu_annex'] ?? null;
    $care = $row['care_level'] ?? null;

    $species = new Species(
        null,
        (string) $row['scientific_name'],
        (string) $row['common_name_de'],
        (string) $row['slug'],
        isset($row['family']) && is_string($row['family']) ? $row['family'] : null,
        isset($row['order_taxon']) && is_string($row['order_taxon']) ? $row['order_taxon'] : null,
        is_string($cites) ? CitesAppendix::from($cites) : null,
        is_string($annex) ? EuAnnex::from($annex) : null,
        BnatschgStatus::from((string) $row['bnatschg_status']),
        (bool) ($row['meldepflicht'] ?? 0),
        (bool) ($row['doku_pflicht'] ?? 0),
        (bool) ($row['gefahrtier'] ?? 0),
        is_string($care) ? CareLevel::from($care) : null,
        isset($row['adult_size_cm']) && is_int($row['adult_size_cm']) ? $row['adult_size_cm'] : null,
        isset($row['lifespan_years']) && is_int($row['lifespan_years']) ? $row['lifespan_years'] : null,
        isset($row['min_abgabe_alter_wochen']) && is_int($row['min_abgabe_alter_wochen']) ? $row['min_abgabe_alter_wochen'] : null,
        isset($row['min_abgabe_gewicht_g']) && is_int($row['min_abgabe_gewicht_g']) ? $row['min_abgabe_gewicht_g'] : null,
    );

    $speciesIds[$species->scientificName] = $speciesRepository->save($species);
}

printf("  %d Arten\n", count($speciesIds));

echo "Merkmalskatalog ...\n";

$morphFile = $readJson($dataPath . '/morphs.json');
/** @var array<string, mixed> $morphGroups */
$morphGroups = is_array($morphFile['morphs'] ?? null) ? $morphFile['morphs'] : [];

$morphCount = 0;
foreach ($morphGroups as $scientificName => $entries) {
    if (!is_array($entries)) {
        continue;
    }

    $speciesId = $speciesIds[$scientificName] ?? null;
    if ($speciesId === null) {
        fwrite(\STDERR, sprintf("  Warnung: Art \"%s\" aus morphs.json fehlt im Artenstamm.\n", (string) $scientificName));

        continue;
    }

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $aliases = [];
        if (is_array($entry['aliases'] ?? null)) {
            foreach ($entry['aliases'] as $alias) {
                if (is_string($alias)) {
                    $aliases[] = $alias;
                }
            }
        }

        $morphRepository->save(new Morph(
            null,
            $speciesId,
            (string) $entry['name'],
            Inheritance::from((string) $entry['inheritance']),
            $aliases,
            isset($entry['allele_group']) && is_string($entry['allele_group']) ? $entry['allele_group'] : null,
            (bool) ($entry['is_lethal_combo'] ?? 0),
            isset($entry['description']) && is_string($entry['description']) ? $entry['description'] : null,
        ));
        ++$morphCount;
    }
}

printf("  %d Merkmale fuer %d Arten\n", $morphCount, count($morphGroups));

echo "Postleitzahlen ...\n";

$importer = new PostalCodeImporter($postalCodeRepository);
$written = $importer->importBundled($dataPath . '/postal_codes.tsv.gz');

printf("  %d Eintraege\n", $written);
foreach (Country::cases() as $country) {
    printf("    %s: %d\n", $country->value, $postalCodeRepository->count($country));
}

echo <<<'TEXT'

    Hinweis: Der Schutzstatus im Artenstamm ist ein gepflegter Ausgangsdatensatz,
    KEINE Rechtsberatung. CITES-Anhaenge und die Anhaenge der EG-VO 338/97 aendern
    sich nach jeder Vertragsstaatenkonferenz. Vor Produktivbetrieb fachlich pruefen
    und danach laufend aktualisieren — die Pflicht dazu liegt beim Betreiber.

    TEXT;

exit(0);
