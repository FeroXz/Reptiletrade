<?php

declare(strict_types=1);

use Reptilienmarkt\Domain\Search\SearchIndex;
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

        Baut den Volltextindex listing_search vollstaendig neu auf.

        Optionen:
          --no-optimize   Den abschliessenden FTS5-Merge auslassen

        Der Index wird im laufenden Betrieb nach jedem Speichern einer Anzeige
        fortgeschrieben; dieser Befehl ist fuer Migrationen und Reparaturen da.

        TEXT;
    exit(0);
}

$indexer = $container->get(ListingIndexer::class);
$index = $container->get(SearchIndex::class);

$start = microtime(true);
$indexed = $indexer->rebuildAll();

if (!in_array('--no-optimize', $argv, true)) {
    $index->optimize();
}

printf(
    "%d Anzeigen indiziert (%.2f s, %d Eintraege im Index).\n",
    $indexed,
    microtime(true) - $start,
    $index->count(),
);

exit(0);
