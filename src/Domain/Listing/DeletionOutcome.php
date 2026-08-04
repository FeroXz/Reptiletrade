<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

/**
 * Was aus dem Loeschwunsch geworden ist.
 *
 * Nicht jede Anzeige laesst sich spurlos entfernen: Haengen Gespraeche oder
 * Bewertungen daran, gehoeren die auch der Gegenseite. Sie mitzuloeschen wuerde
 * fremde Handelshistorie verfaelschen — deshalb wird in dem Fall archiviert
 * statt geloescht, und der Nutzer erfaehrt es.
 */
final readonly class DeletionOutcome
{
    public function __construct(
        public bool $archived,
        public int $filesRemoved,
        public int $conversations,
        public int $reviews,
    ) {}

    public function message(): string
    {
        if (!$this->archived) {
            return 'Die Anzeige ist gelöscht.';
        }

        return 'Die Anzeige ist offline und ihre Bilder sind gelöscht. Vollständig entfernen lässt '
            . 'sie sich nicht: Es hängen Gespräche oder Bewertungen daran, und die gehören auch der '
            . 'jeweils anderen Seite.';
    }
}
