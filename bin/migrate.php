<?php

declare(strict_types=1);

use Reptilienmarkt\Infra\Persistence\MigrationException;
use Reptilienmarkt\Infra\Persistence\Migrator;
use Reptilienmarkt\Support\Container;
use Reptilienmarkt\Support\Env;

if (\PHP_SAPI !== 'cli') {
    exit("bin/migrate.php laeuft nur auf der Kommandozeile.\n");
}

/** @var Container $container */
$container = require dirname(__DIR__) . '/config/bootstrap.php';

/**
 * @param list<string> $argv
 */
$optionValue = static function (array $argv, string $name): ?string {
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--' . $name . '=')) {
            return substr($argument, strlen($name) + 3);
        }
    }

    return null;
};

/** @var list<string> $argv */
$argv = $argv ?? [];
$command = $argv[1] ?? 'status';
$pretend = in_array('--pretend', $argv, true);
$stepOption = $optionValue($argv, 'step');
$step = $stepOption === null ? null : (int) $stepOption;

$migrator = $container->get(Migrator::class);

try {
    switch ($command) {
        case 'up':
            $applied = $migrator->up($step, $pretend);
            if ($applied === []) {
                echo "Keine ausstehenden Migrationen.\n";

                break;
            }
            echo $pretend ? "Wuerde anwenden:\n" : "Angewandt:\n";
            foreach ($applied as $label) {
                echo '  + ' . $label . "\n";
            }

            break;

        case 'down':
            $reverted = $migrator->down($step, $pretend);
            if ($reverted === []) {
                echo "Nichts zurueckzunehmen.\n";

                break;
            }
            echo $pretend ? "Wuerde zuruecknehmen:\n" : "Zurueckgenommen:\n";
            foreach ($reverted as $label) {
                echo '  - ' . $label . "\n";
            }

            break;

        case 'fresh':
            if (Env::string('APP_ENV', 'production') === 'production') {
                fwrite(\STDERR, "fresh ist in der Produktionsumgebung gesperrt.\n");
                exit(1);
            }
            $migrator->down(\PHP_INT_MAX);
            $applied = $migrator->up();
            echo sprintf("Datenbank neu aufgebaut (%d Migrationen).\n", count($applied));

            break;

        case 'status':
            $rows = $migrator->status();
            if ($rows === []) {
                echo "Keine Migrationen gefunden.\n";

                break;
            }
            printf("%-6s %-45s %-12s %-6s %s\n", 'Ver', 'Name', 'Status', 'Batch', 'Angewandt am');
            foreach ($rows as $row) {
                $state = $row['applied'] ? 'angewandt' : 'offen';
                if ($row['changed']) {
                    $state = 'GEAENDERT';
                }
                printf(
                    "%-6s %-45s %-12s %-6s %s\n",
                    $row['version'],
                    substr($row['name'], 0, 45),
                    $state,
                    $row['batch'] === null ? '-' : (string) $row['batch'],
                    $row['applied_at'] ?? '-',
                );
            }

            break;

        default:
            echo <<<'TEXT'
                Verwendung: php bin/migrate.php <befehl> [optionen]

                Befehle:
                  up       [--step=N] [--pretend]   Ausstehende Migrationen anwenden
                  down     [--step=N] [--pretend]   Letzten Batch bzw. N Migrationen zuruecknehmen
                  fresh                             Alles zuruecknehmen und neu aufbauen (nicht in Produktion)
                  status                            Uebersicht anzeigen

                TEXT;
            exit($command === 'help' ? 0 : 1);
    }
} catch (MigrationException $exception) {
    fwrite(\STDERR, 'Migrationsfehler: ' . $exception->getMessage() . "\n");
    exit(1);
}

exit(0);
