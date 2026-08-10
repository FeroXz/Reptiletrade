<?php

declare(strict_types=1);

/**
 * Erzeugt Demo-Anzeigen fuer Leistungsmessungen und die lokale Entwicklung.
 *
 * NICHT fuer den Produktivbetrieb: Die Anzeigen sind erfunden und tragen keine
 * Rechtsnachweise. Aufruf: php tools/generate_demo_listings.php [--anzahl=50000]
 *
 * Wer dieselben Daten bewusst im Produktivbetrieb braucht — etwa um ein leeres
 * Schaufenster zu fuellen —, nimmt tools/generate_demo_listings_produktion.php.
 * Dieses Werkzeug hier bleibt gesperrt, damit ein versehentlicher Aufruf auf
 * dem Server folgenlos bleibt.
 */

use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Demo\DemoDataException;
use Reptilienmarkt\Support\Demo\DemoIndexStrategy;
use Reptilienmarkt\Support\Demo\DemoListingGenerator;
use Reptilienmarkt\Support\Env;

if (\PHP_SAPI !== 'cli') {
    exit("Nur auf der Kommandozeile.\n");
}

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

if (Env::string('APP_ENV', 'production') === 'production') {
    fwrite(\STDERR, "Demo-Daten sind in der Produktionsumgebung gesperrt.\n");
    fwrite(\STDERR, "Bewusst dort gewuenscht? php tools/generate_demo_listings_produktion.php --anzahl=…\n");
    exit(1);
}

/** @var list<string> $argv */
$argv = $argv ?? [];
$count = 50000;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--anzahl=')) {
        $count = max(1, (int) substr($argument, 9));
    }
}

$database = $container->get(Database::class);
$generator = $container->get(DemoListingGenerator::class);

// Zwanzig Motive, die es als Datei nicht gibt: In der Entwicklung zaehlt, dass
// es Bildzeilen gibt — tools/benchmark_search.php misst den Filter "nur mit
// Bild". Im Produktivbetrieb waere das ein toter Bildverweis, deshalb sucht das
// dortige Werkzeug nach echten Dateien.
$bilder = [];
for ($i = 0; $i < 20; ++$i) {
    $bilder[] = sprintf('demo/%d.webp', $i);
}

echo "Demo-Nutzer und Anzeigen ...\n";

try {
    $bericht = $generator->generate(
        count: $count,
        // In der Entwicklung ist der Index danach ohnehin komplett Demo-Daten:
        // ein Neuaufbau am Stueck ist schneller als 50 000 Einzeleintraege.
        strategy: DemoIndexStrategy::Neuaufbau,
        batchSize: 2000,
        mediaPaths: $bilder,
        seed: 20260802,
    );
} catch (DemoDataException $exception) {
    fwrite(\STDERR, $exception->getMessage() . "\n");

    exit(1);
}

$bestand = $generator->inventory();

printf("  %d Nutzer (%d neu)\n", $bestand['users'], $bericht->users);
printf("  %d Anzeigen in %.1f s\n", $bericht->listings, $bericht->seconds);
printf("  %d Merkmale, %d Bilder\n", $bericht->morphs, $bericht->media);
printf("Volltextindex: %d Eintraege\n", $bericht->indexed);

$database->pdo()->exec('ANALYZE');
echo "ANALYZE ausgefuehrt.\n";

exit(0);
