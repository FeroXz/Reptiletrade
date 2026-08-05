<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use DateTimeImmutable;

/**
 * Eine gespeicherte Simulation.
 *
 * Zuechter legen Verpaarungen an, bevor sie sie durchfuehren, und holen den
 * Bericht spaeter wieder hervor — zur Planung, als Beleg gegenueber Kaeufern,
 * als Grundlage einer Nachzucht-Ankuendigung. Deshalb ist das Ergebnis
 * gespeichert und wird nicht bei jedem Aufruf neu gerechnet: Ein Bericht, der
 * sich mit dem Merkmalskatalog aendert, taugt als Beleg nichts.
 */
final readonly class StoredSimulation
{
    public function __construct(
        public ?int $id,
        public int $userId,
        public int $speciesId,
        public string $title,
        public SimulationResult $result,
        public ?int $listingAId = null,
        public ?int $listingBId = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    public function belongsTo(int $userId): bool
    {
        return $this->userId === $userId;
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->userId,
            $this->speciesId,
            $this->title,
            $this->result,
            $this->listingAId,
            $this->listingBId,
            $this->createdAt,
        );
    }
}
