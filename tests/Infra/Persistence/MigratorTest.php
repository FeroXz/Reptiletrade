<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Persistence;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Persistence\MigrationException;
use Reptilienmarkt\Infra\Persistence\Migrator;

#[CoversClass(Migrator::class)]
final class MigratorTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = Database::sqlite(':memory:');
    }

    private function migrator(?string $directory = null): Migrator
    {
        return new Migrator($this->database, $directory ?? \dirname(__DIR__, 3) . '/migrations');
    }

    public function testLaeuftVonLeererDatenbankBisZumAktuellenStand(): void
    {
        $applied = $this->migrator()->up();

        self::assertNotEmpty($applied, 'Es muss mindestens eine Migration angewandt werden.');
        self::assertSame($applied, array_values(array_unique($applied)));

        $tables = $this->tableNames();

        foreach ([
            'users', 'sessions', 'user_tokens', 'species', 'morphs', 'postal_codes', 'listings',
            'listing_morphs', 'listing_media', 'legal_docs', 'conversations', 'messages', 'reviews',
            'reports', 'saved_searches', 'audit_log', 'jobs', 'legal_texts', 'settings', 'listing_search',
        ] as $table) {
            self::assertContains($table, $tables, \sprintf('Tabelle %s fehlt nach der Migration.', $table));
        }
    }

    public function testZweitesUpIstEinNoop(): void
    {
        $this->migrator()->up();

        self::assertSame([], $this->migrator()->up());
    }

    public function testDownNimmtAllesZurueck(): void
    {
        $migrator = $this->migrator();
        $applied = $migrator->up();

        $reverted = $migrator->down(\count($applied));

        self::assertCount(\count($applied), $reverted);
        self::assertSame(array_reverse($applied), $reverted);

        // Uebrig bleiben nur die Verwaltungstabelle des Migrators und die
        // internen Tabellen von SQLite (sqlite_sequence, sqlite_stat1 aus ANALYZE).
        $remaining = array_values(array_filter(
            $this->tableNames(),
            static fn(string $name): bool => $name !== 'migrations' && !str_starts_with($name, 'sqlite_'),
        ));
        self::assertSame([], $remaining, 'Nach dem vollstaendigen Rollback darf keine Fachtabelle uebrig sein.');
    }

    public function testUpDownUpIstWiederholbar(): void
    {
        $migrator = $this->migrator();
        $first = $migrator->up();
        $migrator->down(\count($first));
        $second = $migrator->up();

        self::assertSame($first, $second);
    }

    public function testDownOhneStepNimmtNurDenLetztenBatchZurueck(): void
    {
        $migrator = $this->migrator();
        $migrator->up(2);
        $migrator->up(1);

        $reverted = $migrator->down();

        self::assertCount(1, $reverted);
        self::assertCount(2, $migrator->applied());
    }

    public function testStatusMeldetOffeneUndAngewandteMigrationen(): void
    {
        $migrator = $this->migrator();
        $migrator->up(1);

        $status = $migrator->status();
        $appliedCount = \count(array_filter($status, static fn(array $row): bool => $row['applied']));

        self::assertSame(1, $appliedCount);
        self::assertGreaterThan(1, \count($status));
        self::assertFalse($status[0]['changed']);
    }

    public function testVeraenderteMigrationWirdErkannt(): void
    {
        $migrator = $this->migrator();
        $migrator->up(1);

        // Checksumme kuenstlich verfaelschen: simuliert eine nachtraeglich editierte Datei.
        $this->database->execute("UPDATE migrations SET checksum = 'manipuliert'");

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/veraendert/');

        $migrator->pending();
    }

    public function testFremdschluesselSindNachDerMigrationAktiv(): void
    {
        $this->migrator()->up();

        $value = $this->database->scalar('PRAGMA foreign_keys');

        self::assertSame(1, (int) $value);
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        $statement = $this->database->pdo()->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name",
        );
        self::assertNotFalse($statement);

        /** @var list<string> $names */
        $names = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $names;
    }
}
