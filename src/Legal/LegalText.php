<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

use DateTimeImmutable;

/**
 * Ein redaktionell gepflegter Rechtstext. last_reviewed_at treibt die
 * Admin-Warnung: Texte, die laenger als die konfigurierte Frist nicht geprueft
 * wurden, gelten als veraltet.
 */
final readonly class LegalText
{
    public function __construct(
        public ?int $id,
        public string $key,
        public string $title,
        public string $body,
        public string $jurisdiction = 'DE',
        public ?string $sourceReference = null,
        public ?DateTimeImmutable $lastReviewedAt = null,
    ) {}

    /**
     * Nie geprueft zaehlt als veraltet — sonst wuerde ein frisch eingespielter
     * Seed-Text unbemerkt als geprueft durchgehen.
     */
    public function isStale(DateTimeImmutable $now, int $maxAgeMonths): bool
    {
        if ($this->lastReviewedAt === null) {
            return true;
        }

        return $this->lastReviewedAt < $now->modify(\sprintf('-%d months', $maxAgeMonths));
    }

    public function toNotice(NoticeSeverity $severity = NoticeSeverity::Info): LegalNotice
    {
        return new LegalNotice($this->key, $this->title, $this->body, $this->sourceReference, $severity);
    }
}
