<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use PDO;
use PDOStatement;
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
        $statement = $this->prepare($sql, $parameters);
        $statement->execute();

        return $statement->rowCount();
    }

    /**
     * Bindet jeden Parameter mit seinem Typ.
     *
     * PDO bindet sonst alles als Text. Solange ein Parameter direkt gegen eine
     * Spalte steht, rettet SQLite das ueber die Typaffinitaet der Spalte —
     * gegen einen berechneten Ausdruck aber nicht: Text sortiert dort ueber
     * jede Zahl, und der Vergleich waere immer wahr.
     *
     * @param array<string, scalar|null> $parameters
     */
    private function prepare(string $sql, array $parameters): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        foreach ($parameters as $name => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                \is_bool($value) => PDO::PARAM_BOOL,
                \is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };

            $statement->bindValue(\is_int($name) ? $name + 1 : ':' . $name, $value, $type);
        }

        return $statement;
    }

    /**
     * @param array<string, scalar|null> $parameters
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $parameters = []): array
    {
        $statement = $this->prepare($sql, $parameters);
        $statement->execute();

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
        $statement = $this->prepare($sql, $parameters);
        $statement->execute();

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
