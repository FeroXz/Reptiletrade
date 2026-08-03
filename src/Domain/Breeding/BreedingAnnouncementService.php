<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Breeding;

use DateTimeImmutable;
use Exception;
use Reptilienmarkt\Domain\Billing\EntitlementService;
use Reptilienmarkt\Domain\Billing\Feature;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Support\Clock;

/**
 * Nachzucht-Ankuendigungen — ein Merkmal des Zuechter-Abos.
 *
 * Die Rechtepruefung laeuft ueber EntitlementService: Bei abgeschalteter
 * Monetarisierung darf jeder ankuendigen, danach nur, wer den Tarif hat. Der
 * Dienst selbst kennt weder Tarife noch Preise.
 */
final readonly class BreedingAnnouncementService
{
    public function __construct(
        private BreedingAnnouncementRepository $announcements,
        private SpeciesRepository $species,
        private EntitlementService $entitlements,
        private Clock $clock,
    ) {}

    public function mayAnnounce(User $user): bool
    {
        return $this->entitlements->forUser($user)->has(Feature::NachzuchtAnkuendigung);
    }

    /**
     * @throws AnnouncementException
     */
    public function create(
        User $user,
        int $speciesId,
        string $title,
        ?string $description = null,
        ?string $expectedAt = null,
        ?string $morphNote = null,
    ): BreedingAnnouncement {
        if (!$this->mayAnnounce($user)) {
            throw new AnnouncementException(
                'Ankündigungen kommender Nachzuchten gehören zum Züchter-Tarif.',
            );
        }

        if ($this->species->findById($speciesId) === null) {
            throw new AnnouncementException('Diese Art gibt es nicht.');
        }

        $title = trim($title);
        if (mb_strlen($title) < 5) {
            throw new AnnouncementException('Der Titel braucht mindestens fünf Zeichen.');
        }

        $expected = $this->parseDate($expectedAt);

        // Eine Ankuendigung fuer die Vergangenheit ist keine Ankuendigung.
        if ($expected !== null && $expected < $this->clock->now()->modify('-1 day')) {
            throw new AnnouncementException('Der erwartete Termin liegt in der Vergangenheit.');
        }

        $announcement = new BreedingAnnouncement(
            null,
            $user->id ?? 0,
            $speciesId,
            mb_substr($title, 0, BreedingAnnouncement::MAX_TITLE),
            $description === null ? null : mb_substr(trim($description), 0, BreedingAnnouncement::MAX_DESCRIPTION),
            $expected,
            $morphNote === null || trim($morphNote) === '' ? null : trim($morphNote),
            AnnouncementStatus::Entwurf,
            $this->clock->now(),
        );

        $id = $this->announcements->save($announcement);

        return $this->announcements->findById($id) ?? $announcement;
    }

    /**
     * @throws AnnouncementException
     */
    public function changeStatus(BreedingAnnouncement $announcement, User $user, AnnouncementStatus $status): void
    {
        if (!$announcement->belongsTo($user->id ?? 0)) {
            throw new AnnouncementException('Diese Ankündigung gehört nicht zu deinem Konto.');
        }

        if ($status->isPublic() && !$this->mayAnnounce($user)) {
            throw new AnnouncementException('Ankündigungen kommender Nachzuchten gehören zum Züchter-Tarif.');
        }

        $this->announcements->save(new BreedingAnnouncement(
            $announcement->id,
            $announcement->userId,
            $announcement->speciesId,
            $announcement->title,
            $announcement->description,
            $announcement->expectedAt,
            $announcement->morphNote,
            $status,
            $announcement->createdAt,
        ));
    }

    /**
     * @return list<BreedingAnnouncement>
     */
    public function forUser(User $user): array
    {
        return $this->announcements->forUser($user->id ?? 0);
    }

    /**
     * @return list<BreedingAnnouncement>
     */
    public function publicForSpecies(int $speciesId, int $limit = 10): array
    {
        return $this->announcements->publicForSpecies($speciesId, $limit);
    }

    /**
     * Ueberfaellige Ankuendigungen — Arbeitsvorrat eines Aufraeumjobs.
     *
     * @return list<BreedingAnnouncement>
     */
    public function overdue(int $limit = 100): array
    {
        return $this->announcements->overdue($this->clock->now(), $limit);
    }

    private function parseDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable(trim($value));
        } catch (Exception) {
            return null;
        }
    }
}
