<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Duenne Huelle um PDO. Setzt die Pragmas, die fuer den Betrieb noetig sind, und
 * kapselt Transaktionen. Alle Repositories bekommen diese Klasse injiziert,
 * niemals eine global verfuegbare Verbindung.
 */
final class Database
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Oeffnet eine SQLite-Datenbank. Fuer Dateidatenbanken wird WAL aktiviert,
     * fuer ":memory:" (Tests) entfaellt das, weil WAL dort nicht greift.
     */
    public static function sqlite(string $path): self
    {
        $inMemory = $path === ':memory:';

        if (!$inMemory) {
            $directory = \dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new RuntimeException(\sprintf('Datenbankverzeichnis konnte nicht angelegt werden: %s', $directory));
            }
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        if (!$inMemory) {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        }

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    /**
     * @param array<string, scalar|null> $parameters
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $parameters = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param array<string, scalar|null> $parameters
     *
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $parameters = []): ?array
    {
        $rows = $this->select($sql, $parameters);

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function scalar(string $sql, array $parameters = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        $value = $statement->fetchColumn();

        return $value === false ? null : $value;
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @template T
     *
     * @param callable(self): T $callback
     *
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
