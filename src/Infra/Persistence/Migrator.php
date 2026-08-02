<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use PDO;

/**
 * Versionierte Migrationen. Zustand liegt in der Tabelle "migrations";
 * der Checksum erkennt nachtraeglich veraenderte, bereits angewandte Dateien.
 */
final class Migrator
{
    public function __construct(
        private readonly Database $database,
        private readonly string $directory,
    ) {}

    /**
     * @return list<MigrationFile>
     */
    public function discover(): array
    {
        if (!is_dir($this->directory)) {
            throw new MigrationException(\sprintf('Migrationsverzeichnis fehlt: %s', $this->directory));
        }

        $entries = scandir($this->directory);
        if ($entries === false) {
            throw new MigrationException(\sprintf('Migrationsverzeichnis nicht lesbar: %s', $this->directory));
        }

        $files = [];
        foreach ($entries as $entry) {
            if (preg_match('/^(\d{4})_([a-z0-9_]+)\.php$/', $entry, $matches) !== 1) {
                continue;
            }

            $path = $this->directory . '/' . $entry;
            $checksum = sha1_file($path);
            if ($checksum === false) {
                throw new MigrationException(\sprintf('Migration nicht lesbar: %s', $path));
            }

            $files[] = new MigrationFile($matches[1], $matches[2], $path, $checksum);
        }

        usort($files, static fn(MigrationFile $a, MigrationFile $b): int => strcmp($a->version, $b->version));

        $seen = [];
        foreach ($files as $file) {
            if (isset($seen[$file->version])) {
                throw new MigrationException(\sprintf('Doppelte Migrationsversion %s.', $file->version));
            }
            $seen[$file->version] = true;
        }

        return $files;
    }

    public function ensureRepository(): void
    {
        $this->database->pdo()->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS migrations (
                version    TEXT    NOT NULL PRIMARY KEY,
                name       TEXT    NOT NULL,
                batch      INTEGER NOT NULL,
                checksum   TEXT    NOT NULL,
                applied_at TEXT    NOT NULL
            )
            SQL);
    }

    /**
     * @return array<string, array{name: string, batch: int, checksum: string, applied_at: string}>
     */
    public function applied(): array
    {
        $this->ensureRepository();

        $result = [];
        foreach ($this->database->select('SELECT version, name, batch, checksum, applied_at FROM migrations ORDER BY version') as $row) {
            $result[(string) $row['version']] = [
                'name' => (string) $row['name'],
                'batch' => (int) $row['batch'],
                'checksum' => (string) $row['checksum'],
                'applied_at' => (string) $row['applied_at'],
            ];
        }

        return $result;
    }

    /**
     * @return list<array{version: string, name: string, applied: bool, batch: int|null, applied_at: string|null, changed: bool}>
     */
    public function status(): array
    {
        $applied = $this->applied();
        $status = [];

        foreach ($this->discover() as $file) {
            $record = $applied[$file->version] ?? null;
            $status[] = [
                'version' => $file->version,
                'name' => $file->name,
                'applied' => $record !== null,
                'batch' => $record['batch'] ?? null,
                'applied_at' => $record['applied_at'] ?? null,
                'changed' => $record !== null && $record['checksum'] !== $file->checksum,
            ];
            unset($applied[$file->version]);
        }

        foreach ($applied as $version => $record) {
            $status[] = [
                'version' => $version,
                'name' => $record['name'] . ' (Datei fehlt)',
                'applied' => true,
                'batch' => $record['batch'],
                'applied_at' => $record['applied_at'],
                'changed' => true,
            ];
        }

        return $status;
    }

    /**
     * @return list<MigrationFile>
     */
    public function pending(): array
    {
        $applied = $this->applied();

        $pending = [];
        foreach ($this->discover() as $file) {
            $record = $applied[$file->version] ?? null;

            if ($record === null) {
                $pending[] = $file;

                continue;
            }

            if ($record['checksum'] !== $file->checksum) {
                throw new MigrationException(\sprintf(
                    'Migration %s wurde nach dem Anwenden veraendert. Erwartet %s, gefunden %s. '
                    . 'Bereits angewandte Migrationen duerfen nicht editiert werden — neue Migration anlegen.',
                    $file->label(),
                    $record['checksum'],
                    $file->checksum,
                ));
            }
        }

        return $pending;
    }

    /**
     * Wendet ausstehende Migrationen an.
     *
     * @return list<string> Labels der angewandten Migrationen
     */
    public function up(?int $step = null, bool $pretend = false): array
    {
        $pending = $this->pending();
        if ($step !== null && $step > 0) {
            $pending = \array_slice($pending, 0, $step);
        }

        if ($pending === []) {
            return [];
        }

        $batch = $this->nextBatch();
        $done = [];

        foreach ($pending as $file) {
            if ($pretend) {
                $done[] = $file->label();

                continue;
            }

            $migration = $file->load();
            $this->runGuarded(static function (PDO $pdo) use ($migration): void {
                $migration->up($pdo);
            });

            $this->database->execute(
                'INSERT INTO migrations (version, name, batch, checksum, applied_at) VALUES (:version, :name, :batch, :checksum, :applied_at)',
                [
                    'version' => $file->version,
                    'name' => $file->name,
                    'batch' => $batch,
                    'checksum' => $file->checksum,
                    'applied_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ],
            );

            $done[] = $file->label();
        }

        return $done;
    }

    /**
     * Nimmt Migrationen zurueck. Ohne $step wird der letzte Batch zurueckgerollt.
     *
     * @return list<string> Labels der zurueckgenommenen Migrationen
     */
    public function down(?int $step = null, bool $pretend = false): array
    {
        $applied = $this->applied();
        if ($applied === []) {
            return [];
        }

        $files = [];
        foreach ($this->discover() as $file) {
            $files[$file->version] = $file;
        }

        $versions = array_keys($applied);
        rsort($versions);

        if ($step === null) {
            $lastBatch = 0;
            foreach ($applied as $record) {
                $lastBatch = max($lastBatch, $record['batch']);
            }
            $versions = array_values(array_filter(
                $versions,
                static fn(string $version): bool => $applied[$version]['batch'] === $lastBatch,
            ));
        } else {
            $versions = \array_slice($versions, 0, max(0, $step));
        }

        $done = [];
        foreach ($versions as $version) {
            $file = $files[$version] ?? null;
            if ($file === null) {
                throw new MigrationException(\sprintf(
                    'Migration %s ist angewandt, die Datei fehlt aber — Rollback nicht moeglich.',
                    $version,
                ));
            }

            if ($pretend) {
                $done[] = $file->label();

                continue;
            }

            $migration = $file->load();
            $this->runGuarded(static function (PDO $pdo) use ($migration): void {
                $migration->down($pdo);
            });

            $this->database->execute('DELETE FROM migrations WHERE version = :version', ['version' => $version]);
            $done[] = $file->label();
        }

        return $done;
    }

    private function nextBatch(): int
    {
        $this->ensureRepository();
        $value = $this->database->scalar('SELECT COALESCE(MAX(batch), 0) FROM migrations');

        return (int) (is_numeric($value) ? $value : 0) + 1;
    }

    /**
     * Fuehrt eine Migration in einer Transaktion aus. Fremdschluessel sind waehrenddessen
     * abgeschaltet (SQLite kann Tabellen sonst nicht umbauen) und werden danach geprueft.
     *
     * @param callable(PDO): void $callback
     */
    private function runGuarded(callable $callback): void
    {
        $pdo = $this->database->pdo();
        $pdo->exec('PRAGMA foreign_keys = OFF');

        try {
            $this->database->transaction(static function (Database $database) use ($callback): void {
                $callback($database->pdo());
            });

            $check = $pdo->query('PRAGMA foreign_key_check');
            if ($check !== false) {
                /** @var list<array<string, mixed>> $violations */
                $violations = $check->fetchAll(PDO::FETCH_ASSOC);
                if ($violations !== []) {
                    throw new MigrationException(\sprintf(
                        'Migration hinterlaesst %d verletzte Fremdschluessel-Beziehung(en).',
                        \count($violations),
                    ));
                }
            }
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
}
