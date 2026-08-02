<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

use Reptilienmarkt\Support\Clock;

/**
 * Liefert dem Admin-Dashboard die Rechtstexte, deren Pruefung ueberfaellig ist.
 *
 * Die Plattform leistet keine Rechtsberatung — sie erinnert nur daran, dass die
 * Texte gepflegt werden muessen. Die Pflicht dazu liegt beim Betreiber.
 */
final readonly class LegalTextReview
{
    public function __construct(
        private LegalTextRepository $repository,
        private Clock $clock,
        private int $maxAgeMonths = 12,
    ) {}

    /**
     * @return list<LegalText>
     */
    public function stale(): array
    {
        $now = $this->clock->now();

        $stale = array_filter(
            $this->repository->all(),
            fn(LegalText $text): bool => $text->isStale($now, $this->maxAgeMonths),
        );

        return array_values($stale);
    }

    /**
     * Texte, die noch nie geprueft wurden — die dringendste Teilmenge.
     *
     * @return list<LegalText>
     */
    public function neverReviewed(): array
    {
        return array_values(array_filter(
            $this->repository->all(),
            static fn(LegalText $text): bool => $text->lastReviewedAt === null,
        ));
    }

    public function hasStale(): bool
    {
        return $this->stale() !== [];
    }

    public function maxAgeMonths(): int
    {
        return $this->maxAgeMonths;
    }

    /**
     * Meldung fuer das Admin-Dashboard.
     */
    public function warning(): ?string
    {
        $stale = $this->stale();
        if ($stale === []) {
            return null;
        }

        return \sprintf(
            '%d Rechtstext(e) wurden seit über %d Monaten nicht geprüft: %s',
            \count($stale),
            $this->maxAgeMonths,
            implode(', ', array_map(static fn(LegalText $text): string => $text->key, $stale)),
        );
    }
}
