<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

interface ListingRepository
{
    public function findById(int $id): ?Listing;

    /**
     * Der zuletzt bearbeitete Entwurf eines Nutzers — der Assistent nimmt ihn
     * beim naechsten Besuch wieder auf.
     */
    public function findLatestDraft(int $userId): ?Listing;

    public function create(Listing $listing): int;

    public function save(Listing $listing): void;

    public function updateStatus(int $listingId, ListingStatus $status): void;

    public function delete(int $listingId): void;

    /**
     * Merkmale einer Anzeige samt Auspraegung.
     *
     * @return list<MorphSelection>
     */
    public function morphSelections(int $listingId): array;

    /**
     * Ersetzt die Merkmalsauswahl vollstaendig.
     *
     * @param array<int, Zygosity> $selection Merkmals-ID -> Auspraegung
     */
    public function replaceMorphs(int $listingId, array $selection): void;

    /**
     * @return list<Listing>
     */
    public function forUser(int $userId, int $limit = 50): array;

    /**
     * Sichtbare Anzeigen eines Anbieters — fuer die oeffentliche Profilseite.
     *
     * @return list<Listing>
     */
    public function activeForUser(int $userId, int $limit = 12): array;

    /**
     * Anzeigen in einem bestimmten Zustand, aelteste zuerst — Grundlage der
     * Pruefliste der Moderation.
     *
     * @return list<Listing>
     */
    public function inStatus(ListingStatus $status, int $limit = 25): array;

    /**
     * Setzt die Hervorhebung, nach der die Trefferliste sortiert. Einzige
     * Schreibstelle ist BoostService — sonst waere nicht nachvollziehbar,
     * warum eine Anzeige oben steht.
     */
    public function setFeatured(int $listingId, bool $featured): void;

    /**
     * Steht die Anzeige gerade oben? Bewusst nicht als Feld der Entitaet: Die
     * Hervorhebung gehoert zur Trefferliste, nicht zur Anzeige selbst — wer
     * sie bearbeitet, hat damit nichts zu tun.
     */
    public function isFeatured(int $listingId): bool;
}
