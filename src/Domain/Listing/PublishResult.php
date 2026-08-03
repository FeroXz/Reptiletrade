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
        public bool $autoModerated = false,
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

        if (!$this->needsReview()) {
            return 'Die Anzeige ist online.';
        }

        return $this->autoModerated
            ? 'Die Anzeige wurde eingereicht. Die ersten Anzeigen eines neuen Kontos schauen wir uns kurz an — '
                . 'das dauert in der Regel nicht lange.'
            : 'Die Anzeige wurde eingereicht und wird geprüft. Wir melden uns, sobald sie freigeschaltet ist.';
    }
}
