<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Job;

use DateTimeImmutable;

interface JobRepository
{
    public function findById(int $id): ?Job;

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $type,
        array $payload = [],
        ?DateTimeImmutable $availableAt = null,
        string $queue = 'default',
        int $maxAttempts = 3,
    ): int;

    /**
     * Nimmt den naechsten faelligen Auftrag und markiert ihn als laufend.
     *
     * Reservieren und Auswaehlen muessen ein Schritt sein — sonst greifen zwei
     * Worker nach demselben Auftrag.
     */
    public function reserve(string $workerId, DateTimeImmutable $now, string $queue = 'default'): ?Job;

    public function complete(int $id, DateTimeImmutable $at): void;

    /**
     * Gibt den Auftrag fuer einen weiteren Versuch frei.
     */
    public function release(int $id, DateTimeImmutable $availableAt, string $error): void;

    public function fail(int $id, string $error, DateTimeImmutable $at): void;

    /**
     * Gibt Auftraege frei, deren Worker verschwunden ist.
     *
     * @return int Anzahl der freigegebenen Auftraege
     */
    public function releaseStale(DateTimeImmutable $before, DateTimeImmutable $now): int;

    /**
     * Verhindert doppelte Einplanung wiederkehrender Aufgaben.
     */
    public function hasPending(string $type, string $queue = 'default'): bool;

    /**
     * Wann zuletzt ein Auftrag dieses Typs angelegt wurde — gleich in welchem
     * Zustand. Der Zeitplan braucht das fuer wiederkehrende Abstaende:
     * hasPending() allein weiss nichts mehr von einem Auftrag, den der Worker
     * bereits abgearbeitet hat.
     */
    public function lastEnqueuedAt(string $type): ?DateTimeImmutable;

    /**
     * @return array<string, int>
     */
    public function countsByStatus(): array;

    /**
     * @return list<Job>
     */
    public function recentFailures(int $limit = 20): array;

    public function purgeCompleted(DateTimeImmutable $before): int;
}
