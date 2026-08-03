<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Das oeffentliche Zuechterprofil. Es haengt am Konto, ist aber bewusst eine
 * eigene Einheit: Was hier steht, sieht jeder — was im Konto steht, niemand.
 */
final readonly class BreederProfile
{
    public const int MAX_FOCUS_SPECIES = 8;

    /**
     * @param list<int> $focusSpeciesIds Arten-Schwerpunkt, species.id
     */
    public function __construct(
        public int $userId,
        public string $slug,
        public ?string $headline = null,
        public ?string $description = null,
        public array $focusSpeciesIds = [],
        public ?int $breedingSince = null,
        public ?string $website = null,
        public bool $isPublic = true,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    /**
     * Zuchtjahre, wie sie auf dem Profil stehen. Das laufende Jahr zaehlt mit:
     * Wer 2020 angefangen hat, zuechtet 2026 im sechsten Jahr.
     *
     * Absichtlich DateTimeInterface: Twigs date() liefert ein DateTime, kein
     * DateTimeImmutable — mit der engeren Signatur bricht die Profilseite.
     */
    public function breedingYears(DateTimeInterface $now): ?int
    {
        if ($this->breedingSince === null) {
            return null;
        }

        return max(0, (int) $now->format('Y') - $this->breedingSince);
    }

    public function hasContent(): bool
    {
        return ($this->description !== null && trim($this->description) !== '')
            || ($this->headline !== null && trim($this->headline) !== '');
    }
}
