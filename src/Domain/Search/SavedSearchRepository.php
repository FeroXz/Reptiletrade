<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

use DateTimeImmutable;

interface SavedSearchRepository
{
    public function findById(int $id): ?SavedSearch;

    /**
     * @return list<SavedSearch>
     */
    public function forUser(int $userId): array;

    public function countForUser(int $userId): int;

    public function create(SavedSearch $search, DateTimeImmutable $at): int;

    public function delete(int $id, int $userId): bool;

    public function setAlertFrequency(int $id, int $userId, AlertFrequency $frequency, DateTimeImmutable $at): bool;

    /**
     * Die Suchen, die zu diesem Takt gemeldet werden — nur von aktiven Konten.
     *
     * @return list<SavedSearch>
     */
    public function due(AlertFrequency $frequency, int $limit = 500): array;

    /**
     * Haelt fest, bis zu welcher Anzeige gemeldet wurde.
     */
    public function markAlerted(int $id, int $lastSeenListingId, DateTimeImmutable $at): void;

    /**
     * Die hoechste vergebene Anzeigenkennung.
     *
     * Der Startpunkt einer frisch gemerkten Suche: Wer jetzt merkt, will von
     * jetzt an hoeren und nicht rueckwirkend ueber den ganzen Bestand.
     */
    public function newestListingId(): int;
}
