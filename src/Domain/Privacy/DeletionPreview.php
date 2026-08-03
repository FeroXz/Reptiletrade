<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Privacy;

/**
 * Was eine Loeschung anfassen wuerde. Der Nutzer soll wissen, was passiert,
 * bevor es unwiderruflich wird.
 */
final readonly class DeletionPreview
{
    public function __construct(
        public int $listings,
        public int $conversations,
        public int $reviewsReceived,
        public int $reviewsGiven,
        public bool $willAnonymize,
    ) {}

    public function explanation(): string
    {
        if (!$this->willAnonymize) {
            return 'Dein Konto und alle daran hängenden Daten werden vollständig gelöscht.';
        }

        return 'Zu deinem Konto gibt es Bewertungen. Sie gehören auch der jeweils anderen Seite, '
            . 'deshalb bleiben sie bestehen — dein Konto wird stattdessen anonymisiert: '
            . 'Name, E-Mail, Telefonnummer und Anschrift verschwinden, deine Anzeigen werden '
            . 'abgeschaltet, Bilder und Nachweise gelöscht. Übrig bleibt "Gelöschtes Konto".';
    }
}
