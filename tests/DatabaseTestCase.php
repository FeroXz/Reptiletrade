<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests;

use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Persistence\Migrator;

/**
 * Basis fuer Tests gegen ein vollstaendig migriertes Schema. Jeder Test bekommt
 * eine eigene In-Memory-Datenbank, damit es keine Reihenfolgeabhaengigkeiten gibt.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Database $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = Database::sqlite(':memory:');
        $this->migrator()->up();
    }

    protected function migrator(): Migrator
    {
        return new Migrator($this->database, self::migrationsPath());
    }

    protected static function migrationsPath(): string
    {
        return \dirname(__DIR__) . '/migrations';
    }

    protected static function dataPath(): string
    {
        return \dirname(__DIR__) . '/data';
    }

    /**
     * Legt einen Nutzer an und liefert dessen ID. Viele Tabellen haengen per
     * Fremdschluessel an users.
     */
    protected function createUser(string $email = 'test@example.tld'): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->database->execute(
            'INSERT INTO users (email, email_canonical, password_hash, display_name, created_at, updated_at)
             VALUES (:email, :canonical, :hash, :name, :now, :now)',
            [
                'email' => $email,
                'canonical' => strtolower($email),
                'hash' => 'argon2id$dummy',
                'name' => 'Testnutzer',
                'now' => $now,
            ],
        );

        return $this->database->lastInsertId();
    }

    protected function createSpecies(string $scientificName = 'Pogona vitticeps', string $slug = 'pogona-vitticeps'): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->database->execute(
            'INSERT INTO species (scientific_name, common_name_de, bnatschg_status, slug, created_at, updated_at)
             VALUES (:name, :common, :status, :slug, :now, :now)',
            [
                'name' => $scientificName,
                'common' => 'Testart',
                'status' => 'nicht_geschuetzt',
                'slug' => $slug,
                'now' => $now,
            ],
        );

        return $this->database->lastInsertId();
    }

    protected function createListing(int $userId, int $speciesId, string $status = 'aktiv'): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $this->database->execute(
            'INSERT INTO listings (user_id, type, species_id, title, status, created_at, updated_at)
             VALUES (:user_id, :type, :species_id, :title, :status, :now, :now)',
            [
                'user_id' => $userId,
                'type' => 'verkauf',
                'species_id' => $speciesId,
                'title' => 'Testanzeige',
                'status' => $status,
                'now' => $now,
            ],
        );

        return $this->database->lastInsertId();
    }
}
