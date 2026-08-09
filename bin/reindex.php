<?php

declare(strict_types=1);

use Reptilienmarkt\Domain\Content\ContentSearchIndex;
use Reptilienmarkt\Domain\Search\SearchIndex;
use Reptilienmarkt\Infra\Search\ContentIndexer;
use Reptilienmarkt\Infra\Search\ListingIndexer;
use Reptilienmarkt\Support\Container;

if (\PHP_SAPI !== 'cli') {
    exit("bin/reindex.php laeuft nur auf der Kommandozeile.\n");
}

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

/** @var list<string> $argv */
$argv = $argv ?? [];

if (in_array('--help', $argv, true)) {
    echo <<<'TEXT'
        Verwendung: php bin/reindex.php [optionen]

        Baut die Volltextindizes vollstaendig neu auf.

        Optionen:
          --modul=anzeigen   Nur listing_search
          --modul=inhalte    Nur content_search
          --modul=alle       Beide (Vorgabe)
          --no-optimize      Den abschliessenden FTS5-Merge auslassen

        Die Indizes werden im laufenden Betrieb nach jedem Speichern
        fortgeschrieben; dieser Befehl ist fuer Migrationen und Reparaturen da.

        TEXT;
    exit(0);
}

$modul = 'alle';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--modul=')) {
        $modul = substr($argument, strlen('--modul='));
    }
}

if (!in_array($modul, ['alle', 'anzeigen', 'inhalte'], true)) {
    fwrite(\STDERR, sprintf("Unbekanntes Modul \"%s\". Moeglich sind: alle, anzeigen, inhalte.\n", $modul));

    exit(1);
}

$optimieren = !in_array('--no-optimize', $argv, true);
$start = microtime(true);

if ($modul === 'alle' || $modul === 'anzeigen') {
    $index = $container->get(SearchIndex::class);
    $indiziert = $container->get(ListingIndexer::class)->rebuildAll();

    if ($optimieren) {
        $index->optimize();
    }

    printf("%d Anzeigen indiziert (%d Eintraege im Index).\n", $indiziert, $index->count());
}

if ($modul === 'alle' || $modul === 'inhalte') {
    $inhaltsIndex = $container->get(ContentSearchIndex::class);
    $indiziert = $container->get(ContentIndexer::class)->rebuildAll();

    if ($optimieren) {
        $inhaltsIndex->optimize();
    }

    printf("%d Inhalte indiziert (%d Eintraege im Index).\n", $indiziert, $inhaltsIndex->count());
}

printf("Fertig in %.2f s.\n", microtime(true) - $start);

exit(0);
