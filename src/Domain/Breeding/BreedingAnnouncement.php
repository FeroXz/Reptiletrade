<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Breeding;

use DateTimeImmutable;

/**
 * Eine angekuendigte Nachzucht.
 *
 * Bewusst keine Anzeige: Angekuendigt wird, was es noch nicht gibt. Erst wenn
 * die Tiere da sind, entsteht daraus eine Anzeige — mit Bildern, Gewicht und
 * allem, was die Rechts-Engine sehen will. Eine Ankuendigung als Anzeige zu
 * fuehren hiesse, Tiere anzubieten, die es nicht gibt.
 */
final readonly class BreedingAnnouncement
{
    public const int MAX_TITLE = 120;

    public const int MAX_DESCRIPTION = 2000;

    public function __construct(
        public ?int $id,
        public int $userId,
        public int $speciesId,
        public string $title,
        public ?string $description = null,
        public ?DateTimeImmutable $expectedAt = null,
        public ?string $morphNote = null,
        public AnnouncementStatus $status = AnnouncementStatus::Entwurf,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    public function isPublic(): bool
    {
        return $this->status->isPublic();
    }

    public function belongsTo(int $userId): bool
    {
        return $this->userId === $userId;
    }

    /**
     * Ist der erwartete Termin verstrichen? Dann gehoert die Ankuendigung
     * aufgeraeumt — eine seit Monaten "erwartete" Nachzucht ist ein
     * Vertrauensschaden.
     */
    public function isOverdue(DateTimeImmutable $now): bool
    {
        return $this->expectedAt !== null && $this->expectedAt < $now && $this->status->isPublic();
    }
}
