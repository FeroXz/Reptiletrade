<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

use DateTimeImmutable;

/**
 * Eine gemerkte Suche.
 *
 * Traegt die Kriterien als Objekt, nicht als JSON: Wer damit arbeitet — die
 * Verwaltungsseite, der Alert-Auftrag — soll nicht erst auspacken muessen.
 */
final readonly class SavedSearch
{
    public function __construct(
        public ?int $id,
        public int $userId,
        public string $name,
        public SearchCriteria $criteria,
        public AlertFrequency $alertFrequency = AlertFrequency::Taeglich,
        public ?DateTimeImmutable $lastAlertAt = null,
        public ?int $lastSeenListingId = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}
}
