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

    /**
     * Haelt die Anzeige an und merkt sich, wohin es beim Fortsetzen zurueckgeht.
     */
    public function pause(int $listingId, PauseActor $actor, ?string $reason, ListingStatus $previousStatus): void;

    /**
     * Setzt fort und raeumt die Pausenangaben ab.
     */
    public function resume(int $listingId, ListingStatus $status): void;

    /**
     * Wer, wann, warum — null, wenn die Anzeige nicht pausiert ist.
     */
    public function pauseState(int $listingId): ?PauseState;

    /**
     * Vermerkt eine Bearbeitung nach der Veroeffentlichung.
     *
     * @param array<string, scalar|null> $changed geaenderte Felder mit dem alten Wert
     */
    public function recordEdit(int $listingId, int $editorId, array $changed): void;

    /**
     * Anzahl bisheriger Bearbeitungen.
     */
    public function editCount(int $listingId): int;

    /**
     * Haengt Fremdes an der Anzeige? Danach entscheidet sich, ob eine Loeschung
     * wirklich loescht oder nur archiviert.
     */
    public function conversationCount(int $listingId): int;

    public function reviewCount(int $listingId): int;

    /**
     * Liste fuer die Verwaltung — mit Anbietername und Pausenangaben, damit die
     * Uebersicht nicht je Zeile nachfragen muss.
     *
     * @param array{status?: string, nur_pausiert?: bool, suche?: string} $filters
     *
     * @return list<AdminListingRow>
     */
    public function forAdmin(array $filters = [], int $limit = 100): array;

    /**
     * @return array<string, int> Status => Anzahl
     */
    public function countsByStatus(): array;

    /**
     * Zaehlt einen Aufruf — je Anzeige und Tag.
     */
    public function recordView(int $listingId): void;
}
