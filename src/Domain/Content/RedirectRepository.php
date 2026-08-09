<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use DateTimeImmutable;

interface RedirectRepository
{
    public function findByPath(string $fromPath): ?Redirect;

    public function save(Redirect $redirect): int;

    public function delete(int $id): void;

    /**
     * Entfernt die Weiterleitung, die von diesem Pfad ausgeht — falls es eine
     * gibt. Gebraucht, wenn der Pfad wieder zu einer echten Seite wird.
     */
    public function deleteByPath(string $fromPath): void;

    /**
     * Zaehlt einen Treffer. Getrennt vom Lesen, damit die Auslieferung nicht
     * an einem Schreibvorgang haengt, der scheitern kann.
     */
    public function recordHit(int $id, DateTimeImmutable $moment): void;

    /**
     * @return list<Redirect>
     */
    public function all(int $limit = 200): array;

    /**
     * Alle Weiterleitungen, die auf diesen Pfad zeigen — Grundlage der
     * Kettenaufloesung.
     *
     * @return list<Redirect>
     */
    public function pointingTo(string $toPath): array;

    /**
     * Fuer bin/doctor.php: Paare, die aufeinander zeigen.
     *
     * @return list<array{from: string, to: string}>
     */
    public function loops(): array;

    public function count(): int;
}
