<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Trust;

use DateTimeImmutable;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Domain\User\VerificationLevel;

/**
 * Die ersten Anzeigen eines neuen Kontos gehen in die Pruefung statt direkt
 * online.
 *
 * Die Regel greift bewusst nur bei jungen Konten: Wer seit Monaten dabei ist
 * und bisher nichts eingestellt hat, ist kein Wegwerfkonto, sondern ein
 * zurueckhaltender Nutzer — den in die Warteschlange zu stellen bringt nichts.
 */
final readonly class AutoModerationPolicy
{
    public function __construct(
        private bool $enabled = true,
        private int $firstListings = 3,
        private int $accountAgeDays = 30,
    ) {}

    public function firstListings(): int
    {
        return $this->firstListings;
    }

    /**
     * @param int $publishedCount bereits veroeffentlichte Anzeigen des Kontos
     */
    public function requiresReview(User $user, int $publishedCount, DateTimeImmutable $now): bool
    {
        if (!$this->enabled) {
            return false;
        }

        // Moderation und Administration stellen direkt online.
        if ($user->isModerator()) {
            return false;
        }

        // Ein geprueftes Konto hat den Vertrauensvorschuss schon erbracht.
        if ($user->verificationLevel()->atLeast(VerificationLevel::Identitaet)) {
            return false;
        }

        if ($user->ageInDays($now) > $this->accountAgeDays) {
            return false;
        }

        return $publishedCount < $this->firstListings;
    }

    public function reason(): string
    {
        return \sprintf(
            'Die ersten %d Anzeigen eines neuen Kontos werden vor der Veröffentlichung geprüft.',
            $this->firstListings,
        );
    }
}
