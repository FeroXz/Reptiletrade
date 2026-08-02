<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

/**
 * Eine auf der Platte gefundene Migrationsdatei samt Metadaten.
 */
final readonly class MigrationFile
{
    public function __construct(
        public string $version,
        public string $name,
        public string $path,
        public string $checksum,
    ) {}

    public function load(): Migration
    {
        /** @var mixed $migration */
        $migration = require $this->path;

        if (!$migration instanceof Migration) {
            throw new MigrationException(\sprintf(
                'Migration %s_%s muss eine Migration-Instanz zurueckgeben, %s erhalten.',
                $this->version,
                $this->name,
                get_debug_type($migration),
            ));
        }

        return $migration;
    }

    public function label(): string
    {
        return $this->version . '_' . $this->name;
    }
}
