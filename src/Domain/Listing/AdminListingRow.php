<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

use DateTimeImmutable;

/**
 * Eine Zeile der Anzeigenliste in der Verwaltung.
 *
 * Kein Listing: Die Uebersicht braucht Anbietername und Artname, die Entitaet
 * aber kennt nur Kennungen. Sie je Zeile nachzuschlagen waeren hundert
 * Abfragen fuer eine Seite — deshalb ein eigenes Lesemodell mit den Feldern,
 * die die Liste tatsaechlich zeigt.
 */
final readonly class AdminListingRow
{
    public function __construct(
        public int $id,
        public string $title,
        public ListingStatus $status,
        public int $userId,
        public string $sellerName,
        public string $speciesName,
        public DateTimeImmutable $createdAt,
        public ?PauseActor $pausedBy = null,
        public ?DateTimeImmutable $pausedAt = null,
        public ?string $pausedReason = null,
        public int $editCount = 0,
        public int $reportCount = 0,
    ) {}

    public function isPaused(): bool
    {
        return $this->status === ListingStatus::Pausiert;
    }

    public function pausedByAdmin(): bool
    {
        return $this->pausedBy === PauseActor::Verwaltung;
    }
}
