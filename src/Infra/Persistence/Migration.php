<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use PDO;

/**
 * Jede Migrationsdatei gibt genau eine Implementierung dieses Interfaces zurueck.
 * Es gibt bewusst keinen Schema-Diff und keine Auto-Synchronisierung.
 */
interface Migration
{
    public function up(PDO $pdo): void;

    public function down(PDO $pdo): void;
}
