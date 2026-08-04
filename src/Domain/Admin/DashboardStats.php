<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Admin;

use Reptilienmarkt\Legal\LegalText;

final readonly class DashboardStats
{
    /**
     * @param array<string, int>                        $listingsPerDay
     * @param list<array{art: string, anzahl: int}>     $topSpecies
     * @param list<LegalText>                           $staleLegalTexts
     * @param array<string, int>                        $jobCounts
     */
    public function __construct(
        public array $listingsPerDay,
        public array $topSpecies,
        public int $openReports,
        public int $listingsInReview,
        public int $pendingDocuments,
        public int $flaggedMessages,
        public int $activeListings,
        public int $pausedListings,
        public int $activeUsers,
        public int $newUsersThisWeek,
        public array $staleLegalTexts,
        public array $jobCounts,
    ) {}

    /**
     * Alles, was auf einen Menschen wartet.
     */
    public function pendingTotal(): int
    {
        return $this->openReports + $this->listingsInReview + $this->pendingDocuments;
    }

    public function failedJobs(): int
    {
        return $this->jobCounts['fehlgeschlagen'] ?? 0;
    }

    public function waitingJobs(): int
    {
        return $this->jobCounts['wartend'] ?? 0;
    }

    /**
     * Groesster Tageswert — Bezugsgroesse fuer die Balkenhoehe.
     */
    public function peakPerDay(): int
    {
        return $this->listingsPerDay === [] ? 0 : max($this->listingsPerDay);
    }
}
