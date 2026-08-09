<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobRepository;
use Reptilienmarkt\Domain\Job\JobStatus;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoJobRepository implements JobRepository
{
    private const string COLUMNS = 'id, queue, type, payload_json, status, attempts, max_attempts, available_at, '
        . 'reserved_at, reserved_by, completed_at, last_error, created_at';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?Job
    {
        $row = $this->database->selectOne('SELECT ' . self::COLUMNS . ' FROM jobs WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->map($row);
    }

    public function enqueue(
        string $type,
        array $payload = [],
        ?DateTimeImmutable $availableAt = null,
        string $queue = 'default',
        int $maxAttempts = 3,
    ): int {
        $this->database->execute(
            'INSERT INTO jobs (queue, type, payload_json, available_at, max_attempts, created_at)
             VALUES (:queue, :type, :payload, :available, :max_attempts, :now)',
            [
                'queue' => $queue,
                'type' => $type,
                'payload' => json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'available' => Timestamp::utcOrNull($availableAt) ?? Timestamp::now(),
                'max_attempts' => $maxAttempts,
                'now' => Timestamp::now(),
            ],
        );

        return $this->database->lastInsertId();
    }

    /**
     * Auswaehlen und Reservieren in einem Schritt.
     *
     * Das UPDATE trifft genau eine Zeile und setzt dabei den Worker ein. Wer
     * erst liest und dann schreibt, riskiert, dass zwei Worker denselben
     * Auftrag greifen — bei SQLite mit seinem einzigen Schreiber faellt das
     * selten auf, bei PostgreSQL sofort. Deshalb hier gleich richtig.
     */
    public function reserve(string $workerId, DateTimeImmutable $now, string $queue = 'default'): ?Job
    {
        $stamp = Timestamp::utc($now);

        return $this->database->transaction(function (Database $database) use ($workerId, $stamp, $queue): ?Job {
            $betroffen = $database->execute(
                <<<'SQL'
                    UPDATE jobs
                       SET status = 'laeuft',
                           reserved_at = :now,
                           reserved_by = :worker,
                           attempts = attempts + 1
                     WHERE id = (
                         SELECT id FROM jobs
                          WHERE queue = :queue
                            AND status = 'wartend'
                            AND available_at <= :now
                          ORDER BY available_at ASC, id ASC
                          LIMIT 1
                     )
                    SQL,
                ['now' => $stamp, 'worker' => $workerId, 'queue' => $queue],
            );

            if ($betroffen === 0) {
                return null;
            }

            $row = $database->selectOne(
                'SELECT ' . self::COLUMNS . " FROM jobs
                  WHERE reserved_by = :worker AND status = 'laeuft'
                  ORDER BY reserved_at DESC, id DESC LIMIT 1",
                ['worker' => $workerId],
            );

            return $row === null ? null : $this->map($row);
        });
    }

    public function complete(int $id, DateTimeImmutable $at): void
    {
        $this->database->execute(
            "UPDATE jobs SET status = 'erledigt', completed_at = :now, reserved_by = NULL, last_error = NULL
              WHERE id = :id",
            ['now' => Timestamp::utc($at), 'id' => $id],
        );
    }

    public function release(int $id, DateTimeImmutable $availableAt, string $error): void
    {
        $this->database->execute(
            "UPDATE jobs SET status = 'wartend', available_at = :available, reserved_at = NULL,
                             reserved_by = NULL, last_error = :error
              WHERE id = :id",
            ['available' => Timestamp::utc($availableAt), 'error' => mb_substr($error, 0, 1000), 'id' => $id],
        );
    }

    public function fail(int $id, string $error, DateTimeImmutable $at): void
    {
        $this->database->execute(
            "UPDATE jobs SET status = 'fehlgeschlagen', last_error = :error, completed_at = :now, reserved_by = NULL
              WHERE id = :id",
            ['error' => mb_substr($error, 0, 1000), 'now' => Timestamp::utc($at), 'id' => $id],
        );
    }

    public function releaseStale(DateTimeImmutable $before, DateTimeImmutable $now): int
    {
        // Der Versuchszaehler bleibt stehen: Ein abgestuerzter Worker hat den
        // Auftrag angefasst, und ein Auftrag, der jeden Worker mitnimmt, soll
        // nicht ewig kreisen.
        return $this->database->execute(
            <<<'SQL'
                UPDATE jobs
                   SET status = 'wartend',
                       available_at = :now,
                       reserved_at = NULL,
                       reserved_by = NULL,
                       last_error = 'Worker verschwunden, Auftrag freigegeben.'
                 WHERE status = 'laeuft' AND reserved_at < :before
                SQL,
            ['now' => Timestamp::utc($now), 'before' => Timestamp::utc($before)],
        );
    }

    public function hasPending(string $type, string $queue = 'default'): bool
    {
        $value = $this->database->scalar(
            "SELECT COUNT(*) FROM jobs WHERE type = :type AND queue = :queue AND status IN ('wartend','laeuft')",
            ['type' => $type, 'queue' => $queue],
        );

        return (int) (is_numeric($value) ? $value : 0) > 0;
    }

    public function lastEnqueuedAt(string $type): ?DateTimeImmutable
    {
        $value = $this->database->scalar(
            'SELECT MAX(created_at) FROM jobs WHERE type = :type',
            ['type' => $type],
        );

        return \is_string($value) ? Timestamp::parse($value) : null;
    }

    public function countsByStatus(): array
    {
        $counts = [];

        foreach ($this->database->select('SELECT status, COUNT(*) AS anzahl FROM jobs GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['anzahl'];
        }

        return $counts;
    }

    public function recentFailures(int $limit = 20): array
    {
        $rows = $this->database->select(
            'SELECT ' . self::COLUMNS . " FROM jobs WHERE status = 'fehlgeschlagen'
             ORDER BY completed_at DESC, id DESC LIMIT :limit",
            ['limit' => $limit],
        );

        return array_map($this->map(...), $rows);
    }

    public function purgeCompleted(DateTimeImmutable $before): int
    {
        // Gescheiterte Auftraege bleiben liegen — sie warten auf einen
        // Menschen, und ein aufgeraeumter Fehler ist ein verlorener Hinweis.
        return $this->database->execute(
            "DELETE FROM jobs WHERE status = 'erledigt' AND completed_at < :before",
            ['before' => Timestamp::utc($before)],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Job
    {
        /** @var mixed $payload */
        $payload = json_decode((string) $row['payload_json'], true);

        $clean = [];
        if (\is_array($payload)) {
            foreach ($payload as $key => $value) {
                if (\is_string($key)) {
                    $clean[$key] = $value;
                }
            }
        }

        return new Job(
            (int) $row['id'],
            (string) $row['type'],
            $clean,
            (string) $row['queue'],
            JobStatus::from((string) $row['status']),
            (int) $row['attempts'],
            (int) $row['max_attempts'],
            Timestamp::parse((string) $row['available_at']),
            Timestamp::parse(\is_string($row['reserved_at']) ? $row['reserved_at'] : null),
            \is_string($row['reserved_by']) ? $row['reserved_by'] : null,
            Timestamp::parse(\is_string($row['completed_at']) ? $row['completed_at'] : null),
            \is_string($row['last_error']) ? $row['last_error'] : null,
            Timestamp::parse(\is_string($row['created_at']) ? $row['created_at'] : null),
        );
    }
}
