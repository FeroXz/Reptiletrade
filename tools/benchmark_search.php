<?php

declare(strict_types=1);

/**
 * Misst die Facettensuche und schreibt die Abfrageplaene nach docs/SUCHE.md.
 *
 * Voraussetzung: tools/generate_demo_listings.php wurde ausgefuehrt.
 * Aufruf: php tools/benchmark_search.php [--laeufe=20] [--schreiben]
 */

use Reptilienmarkt\Domain\Geo\Coordinates;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Listing\CbStatus;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Listing\Zygosity;
use Reptilienmarkt\Domain\Search\MorphFilter;
use Reptilienmarkt\Domain\Search\RadiusFilter;
use Reptilienmarkt\Domain\Search\SearchCriteria;
use Reptilienmarkt\Domain\Search\SearchRadius;
use Reptilienmarkt\Domain\Search\SortOrder;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Search\PdoListingSearchRepository;
use Reptilienmarkt\Support\Container;

if (\PHP_SAPI !== 'cli') {
    exit("Nur auf der Kommandozeile.\n");
}

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

/** @var list<string> $argv */
$argv = $argv ?? [];
$runs = 20;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--laeufe=')) {
        $runs = max(1, (int) substr($argument, 9));
    }
}
$write = in_array('--schreiben', $argv, true);

$database = $container->get(Database::class);
/** @var PdoListingSearchRepository $repository */
$repository = $container->get(\Reptilienmarkt\Domain\Search\ListingSearchRepository::class);

$total = (int) $database->scalar('SELECT COUNT(*) FROM listings');
$visible = (int) $database->scalar("SELECT COUNT(*) FROM listings WHERE status IN ('aktiv','reserviert')");

printf("Datenbestand: %d Anzeigen, davon %d oeffentlich sichtbar.\n\n", $total, $visible);

if ($total < 1000) {
    fwrite(\STDERR, "Zu wenig Daten — bitte zuerst tools/generate_demo_listings.php ausfuehren.\n");
    exit(1);
}

$speciesId = (int) $database->scalar(
    "SELECT species_id FROM listings WHERE status = 'aktiv' GROUP BY species_id ORDER BY COUNT(*) DESC LIMIT 1",
);

/** @var list<int> $morphIds */
$morphIds = [];
foreach ($database->select(
    'SELECT lm.morph_id FROM listing_morphs lm JOIN morphs m ON m.id = lm.morph_id
      WHERE m.species_id = :species GROUP BY lm.morph_id ORDER BY COUNT(*) DESC LIMIT 2',
    ['species' => $speciesId],
) as $row) {
    $morphIds[] = (int) $row['morph_id'];
}

$muenchen = new RadiusFilter(
    new Coordinates(48.13743, 11.57549),
    SearchRadius::Km50,
    '80331',
    Country::De,
    'München',
);

/** @var array<string, SearchCriteria> $scenarios */
$scenarios = [
    'Startseite ohne Filter' => new SearchCriteria(),
    'Art gefiltert' => new SearchCriteria(speciesId: $speciesId),
    'Volltext "python"' => new SearchCriteria(query: 'python', sort: SortOrder::Relevanz),
    'Umkreis 50 km, nach Entfernung' => new SearchCriteria(radius: $muenchen, sort: SortOrder::Entfernung),
    'Umkreis 200 km' => new SearchCriteria(
        radius: new RadiusFilter(new Coordinates(52.52437, 13.41053), SearchRadius::Km200, '10115', Country::De, 'Berlin'),
        sort: SortOrder::Entfernung,
    ),
    'Kombination Art + 2 Morphs + Umkreis + Preis' => new SearchCriteria(
        speciesId: $speciesId,
        morphs: array_map(
            static fn(int $id): MorphFilter => new MorphFilter($id, [Zygosity::Visual, Zygosity::Het]),
            $morphIds,
        ),
        sexes: [Sex::Weiblich],
        types: [ListingType::Verkauf],
        cbStatuses: [CbStatus::Nachzucht],
        priceMinCents: 5000,
        priceMaxCents: 150000,
        withImageOnly: true,
        radius: $muenchen,
    ),
    'Seite 20 (tiefe Paginierung)' => new SearchCriteria(page: 20),
];

$limit = 200.0;
$rows = [];
$failed = false;

foreach ($scenarios as $name => $criteria) {
    // Einmal warmlaufen, damit der Seitencache nicht die erste Messung verzerrt.
    $repository->search($criteria);

    $timings = [];
    $result = $repository->search($criteria);
    for ($i = 0; $i < $runs; ++$i) {
        $start = microtime(true);
        $result = $repository->search($criteria);
        $timings[] = (microtime(true) - $start) * 1000;
    }

    sort($timings);
    $median = $timings[intdiv(count($timings), 2)];
    $p95 = $timings[min(count($timings) - 1, (int) floor(count($timings) * 0.95))];
    $treffer = $result->total;

    $rows[] = [$name, $treffer, $median, $p95];
    $failed = $failed || $p95 > $limit;

    printf(
        "%-46s %8d Treffer   Median %6.1f ms   p95 %6.1f ms %s\n",
        $name,
        $treffer,
        $median,
        $p95,
        $p95 > $limit ? '  ZU LANGSAM' : '',
    );
}

echo "\n";

$plans = [];
foreach (['Startseite ohne Filter', 'Umkreis 50 km, nach Entfernung', 'Kombination Art + 2 Morphs + Umkreis + Preis', 'Volltext "python"'] as $name) {
    $plans[$name] = $repository->explain($scenarios[$name]);
}

foreach ($plans as $name => $plan) {
    echo "== {$name}\n";
    foreach ($plan as $kind => $lines) {
        echo "  [{$kind}]\n";
        foreach ($lines as $line) {
            echo '    ' . $line . "\n";
        }
    }
    echo "\n";
}

if ($write) {
    $document = "# Suche — Messwerte und Abfragepläne\n\n"
        . "Erzeugt mit `php tools/benchmark_search.php --schreiben`.\n\n"
        . sprintf(
            "Datenbestand: **%d Anzeigen**, davon %d öffentlich sichtbar. SQLite %s, %d Läufe je Szenario.\n\n",
            $total,
            $visible,
            (string) $database->scalar('SELECT sqlite_version()'),
            $runs,
        )
        . "## Messwerte\n\n"
        . "| Szenario | Treffer | Median | p95 |\n|---|---:|---:|---:|\n";

    foreach ($rows as [$name, $treffer, $median, $p95]) {
        $document .= sprintf("| %s | %d | %.1f ms | %.1f ms |\n", $name, $treffer, $median, $p95);
    }

    $document .= "\nZielmarke: unter 200 ms bei 50 000 Anzeigen.\n\n## Abfragepläne\n\n";

    foreach ($plans as $name => $plan) {
        $document .= "### {$name}\n\n";
        foreach ($plan as $kind => $lines) {
            $document .= "**{$kind}**\n\n```\n" . implode("\n", $lines) . "\n```\n\n";
        }
    }

    file_put_contents(dirname(__DIR__) . '/docs/SUCHE.md', $document);
    echo "docs/SUCHE.md geschrieben.\n";
}

exit($failed ? 1 : 0);
