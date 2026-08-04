<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

use DateTimeImmutable;

/**
 * Wer, wann, warum — und wohin es beim Fortsetzen zurueckgeht.
 *
 * Bewusst nicht in der Entitaet Listing: Der Zustand "pausiert" steht dort
 * bereits im Status. Was hier steht, ist Verwaltungswissen ueber die Pause und
 * gehoert nicht in jedes Formular, das eine Anzeige speichert — genauso wie die
 * Hervorhebung aus Phase 6 aussen vor bleibt.
 */
final readonly class PauseState
{
    public function __construct(
        public PauseActor $actor,
        public DateTimeImmutable $at,
        public ?string $reason,
        public ListingStatus $previousStatus,
    ) {}

    public function byAdmin(): bool
    {
        return $this->actor === PauseActor::Verwaltung;
    }

    /**
     * Was der Anbieter zu sehen bekommt. Der Grund der Verwaltung wird genannt —
     * wer nicht erfaehrt, warum seine Anzeige stillsteht, kann es nicht abstellen.
     */
    public function explanation(): string
    {
        if (!$this->byAdmin()) {
            return 'Du hast diese Anzeige pausiert. Sie ist für Käufer nicht sichtbar.';
        }

        return 'Diese Anzeige wurde von der Verwaltung pausiert'
            . ($this->reason === null || $this->reason === '' ? '.' : ': ' . $this->reason)
            . ' Sie lässt sich nur von der Verwaltung wieder freigeben.';
    }
}
