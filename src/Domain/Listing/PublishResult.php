<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

use Reptilienmarkt\Legal\LegalDecision;

final readonly class PublishResult
{
    public function __construct(
        public bool $published,
        public ListingStatus $status,
        public LegalDecision $decision,
        /** @var list<string> */
        public array $errors = [],
    ) {}

    public function needsReview(): bool
    {
        return $this->status === ListingStatus::Pruefung;
    }

    public function message(): string
    {
        if (!$this->published) {
            return 'Die Anzeige kann noch nicht veröffentlicht werden.';
        }

        return $this->needsReview()
            ? 'Die Anzeige wurde eingereicht und wird geprüft. Wir melden uns, sobald sie freigeschaltet ist.'
            : 'Die Anzeige ist online.';
    }
}
