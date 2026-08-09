<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Support\Clock;

/**
 * Suchen merken, verwalten, wieder loswerden.
 *
 * Die Tabelle saved_searches gab es seit Phase 2, samt Auskunft, Loeschung und
 * taeglichem Auftrag — nur schrieb nie jemand eine Zeile hinein. Diese Schicht
 * ist die fehlende Haelfte.
 */
final readonly class SavedSearchService
{
    public const int MAX_NAME_LENGTH = 80;

    public function __construct(
        private SavedSearchRepository $searches,
        private AuditLog $audit,
        private Clock $clock,
        private int $maxPerAccount,
    ) {}

    /**
     * @return list<SavedSearch>
     */
    public function forUser(int $userId): array
    {
        return $this->searches->forUser($userId);
    }

    public function limit(): int
    {
        return $this->maxPerAccount;
    }

    /**
     * @throws SavedSearchException
     */
    public function save(int $userId, string $name, SearchCriteria $criteria): SavedSearch
    {
        $name = trim($name);

        if ($name === '') {
            throw new SavedSearchException('Gib der Suche einen Namen, damit du sie wiedererkennst.');
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $name = mb_substr($name, 0, self::MAX_NAME_LENGTH);
        }

        if (!$criteria->isFiltered()) {
            // Eine Suche ohne jeden Filter ist der ganze Marktplatz. Sie zu
            // merken hiesse, taeglich ueber jede neue Anzeige zu schreiben.
            throw new SavedSearchException('Stelle zuerst einen Filter ein — sonst merkst du dir den ganzen Markt.');
        }

        if ($this->searches->countForUser($userId) >= $this->maxPerAccount) {
            throw new SavedSearchException(\sprintf(
                'Du kannst höchstens %d Suchen merken. Lösche zuerst eine bestehende.',
                $this->maxPerAccount,
            ));
        }

        $search = new SavedSearch(
            null,
            $userId,
            $name,
            // Ohne Seitenzahl und mit der Sortierung, die der Nutzer sah: Die
            // Kriterien sind die Frage, das Blaettern gehoert nicht dazu.
            $criteria->withPage(1),
            AlertFrequency::Taeglich,
            lastSeenListingId: $this->searches->newestListingId(),
        );

        $id = $this->searches->create($search, $this->clock->now());

        $this->audit->record(new AuditEntry('saved_search.created', 'saved_search', $id, ['name' => $name], $userId));

        return $this->searches->findById($id) ?? $search;
    }

    /**
     * @throws SavedSearchException
     */
    public function delete(int $userId, int $id): void
    {
        if (!$this->searches->delete($id, $userId)) {
            // Dieselbe Meldung fuer "gibt es nicht" und "gehoert dir nicht":
            // Der Unterschied verriete, welche Kennungen vergeben sind.
            throw new SavedSearchException('Diese gespeicherte Suche gibt es nicht.');
        }

        $this->audit->record(new AuditEntry('saved_search.deleted', 'saved_search', $id, [], $userId));
    }

    /**
     * @throws SavedSearchException
     */
    public function setAlertFrequency(int $userId, int $id, AlertFrequency $frequency): void
    {
        if (!$this->searches->setAlertFrequency($id, $userId, $frequency, $this->clock->now())) {
            throw new SavedSearchException('Diese gespeicherte Suche gibt es nicht.');
        }

        $this->audit->record(new AuditEntry(
            'saved_search.alert_changed',
            'saved_search',
            $id,
            ['frequenz' => $frequency->value],
            $userId,
        ));
    }
}
