<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\User;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Audit\AuditActorType;
use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Auth\SessionRepository;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Domain\Listing\PauseActor;
use Reptilienmarkt\Domain\Privacy\AccountDeletionService;
use Reptilienmarkt\Domain\Privacy\DeletionResult;
use Reptilienmarkt\Domain\Privacy\PrivacyException;
use Reptilienmarkt\Infra\Search\ListingIndexer;
use Reptilienmarkt\Support\Clock;

/**
 * Kontosperren durch die Verwaltung.
 *
 * Eine Sperre muss drei Dinge tun, sonst ist sie keine:
 *
 * 1. **Den Zugang schliessen.** Der Status allein reicht nicht — er wird erst
 *    bei der naechsten Anmeldung geprueft. Wer gerade angemeldet ist, bliebe es
 *    sonst bis zum Ablauf seiner Sitzung. Deshalb werden alle Sitzungen des
 *    Kontos verworfen.
 *
 * 2. **Die Anzeigen vom Markt nehmen.** Sie werden pausiert, nicht geloescht:
 *    Eine befristete Sperre soll den Anbieter nach Ablauf nicht vor einem
 *    leeren Konto stehen lassen. Beim Aufheben laufen sie wieder an.
 *
 * 3. **Den Grund festhalten.** Fuer den Betroffenen, der nachfragen koennen
 *    muss, und fuer den Audit-Trail.
 *
 * Geloescht wird ueber denselben Dienst wie bei der Selbstloeschung: Auch die
 * Verwaltung darf die Bewertungshistorie der Gegenseite nicht ausradieren.
 */
final readonly class UserModerationService
{
    private const int MAX_REASON_LENGTH = 300;

    /**
     * Kennung, an der das Entsperren "seine" Pausen wiedererkennt. Sie steht am
     * Anfang jedes Pausengrundes, den eine Kontosperre schreibt — befristet wie
     * unbefristet. Beide Wortlaute muessen dieselbe Kennung tragen, sonst
     * bleiben die Anzeigen einer befristeten Sperre fuer immer pausiert.
     */
    private const string BAN_MARKER = 'Konto gesperrt';

    public function __construct(
        private UserRepository $users,
        private SessionRepository $sessions,
        private ListingRepository $listings,
        private ListingIndexer $indexer,
        private AccountDeletionService $deletion,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    /**
     * @throws UserModerationException
     */
    public function ban(User $target, User $admin, string $reason, ?DateTimeImmutable $until = null): void
    {
        $this->guard($target, $admin);

        $now = $this->clock->now();

        if ($until !== null && $until <= $now) {
            throw new UserModerationException('Das Ende der Sperre muss in der Zukunft liegen.');
        }

        $grund = mb_substr(trim($reason), 0, self::MAX_REASON_LENGTH);

        if ($grund === '') {
            throw new UserModerationException('Bitte gib einen Grund an. Der Gesperrte bekommt ihn zu sehen.');
        }

        $id = $target->id ?? 0;

        $this->users->ban($id, $now, $until, $grund, $admin->id);
        $this->sessions->deleteForUser($id);
        $pausiert = $this->hideListings($id, $until);

        $this->audit->record(new AuditEntry(
            'user.banned',
            'user',
            $id,
            [
                'grund' => $grund,
                'bis' => $until?->format('c'),
                'dauerhaft' => $until === null,
                'anzeigen_pausiert' => $pausiert,
            ],
            $admin->id,
            AuditActorType::Admin,
        ));
    }

    /**
     * @throws UserModerationException
     */
    public function unban(User $target, ?User $admin = null): void
    {
        if ($target->status === UserStatus::Geloescht) {
            throw new UserModerationException('Ein gelöschtes Konto lässt sich nicht entsperren.');
        }

        $id = $target->id ?? 0;

        $this->users->unban($id);
        $wieder = $this->restoreListings($id);

        $this->audit->record(new AuditEntry(
            $admin === null ? 'user.ban_expired' : 'user.unbanned',
            'user',
            $id,
            ['anzeigen_fortgesetzt' => $wieder],
            $admin?->id,
            $admin === null ? AuditActorType::System : AuditActorType::Admin,
        ));
    }

    /**
     * Loescht ein Konto — mit derselben Abwaegung wie bei der Selbstloeschung:
     * Gibt es Bewertungen, wird anonymisiert statt geloescht.
     *
     * @throws UserModerationException
     */
    public function delete(User $target, User $admin): DeletionResult
    {
        $this->guard($target, $admin);

        try {
            $ergebnis = $this->deletion->delete($target, $admin->id);
        } catch (PrivacyException $exception) {
            throw new UserModerationException($exception->getMessage(), 0, $exception);
        }

        $this->audit->record(new AuditEntry(
            'user.deleted_by_admin',
            'user',
            $target->id,
            ['anonymisiert' => $ergebnis->anonymized],
            $admin->id,
            AuditActorType::Admin,
        ));

        return $ergebnis;
    }

    /**
     * Hebt abgelaufene Sperren auf — Aufgabe des Jobs, nicht eines Menschen.
     *
     * @return int Anzahl entsperrter Konten
     */
    public function releaseExpired(): int
    {
        $entsperrt = 0;

        foreach ($this->users->withExpiredBan($this->clock->now()) as $user) {
            $this->unban($user);
            ++$entsperrt;
        }

        return $entsperrt;
    }

    public function banState(User $user): ?BanState
    {
        return $this->users->banState($user->id ?? 0);
    }

    /**
     * @throws UserModerationException
     */
    private function guard(User $target, User $admin): void
    {
        if ($admin->role !== Role::Admin) {
            throw new UserModerationException('Dafür fehlt die Berechtigung.');
        }

        if ($target->id === $admin->id) {
            throw new UserModerationException('Das eigene Konto lässt sich hier nicht sperren oder löschen.');
        }

        // Ein Administrator kann einen anderen nicht im Vorbeigehen abschalten.
        // Wer das wirklich will, nimmt ihm zuerst die Rolle — und das ist eine
        // bewusste zweite Handlung.
        if ($target->role === Role::Admin) {
            throw new UserModerationException(
                'Ein Konto mit Verwaltungsrechten lässt sich so nicht sperren. Nimm ihm zuerst die Rolle.',
            );
        }
    }

    /**
     * Anzeigen vom Markt nehmen — pausiert, nicht geloescht.
     */
    private function hideListings(int $userId, ?DateTimeImmutable $until): int
    {
        $grund = $until === null
            ? self::BAN_MARKER . ' — die Anzeige ist bis auf Weiteres nicht sichtbar.'
            : \sprintf('%s bis zum %s — die Anzeige ist bis dahin nicht sichtbar.', self::BAN_MARKER, $until->format('d.m.Y'));

        $pausiert = 0;

        foreach ($this->listings->forUser($userId, 200) as $listing) {
            if (!$listing->status->isPausable() || $listing->id === null) {
                continue;
            }

            $this->listings->pause($listing->id, PauseActor::Verwaltung, $grund, $listing->status);
            $this->indexer->removeListing($listing->id);
            ++$pausiert;
        }

        return $pausiert;
    }

    /**
     * Beim Entsperren laufen genau die Anzeigen wieder an, die die Sperre
     * angehalten hat — erkennbar am Vermerk der Verwaltung.
     */
    private function restoreListings(int $userId): int
    {
        $fortgesetzt = 0;

        foreach ($this->listings->forUser($userId, 200) as $listing) {
            if ($listing->status !== ListingStatus::Pausiert || $listing->id === null) {
                continue;
            }

            $pause = $this->listings->pauseState($listing->id);

            if ($pause === null || $pause->actor !== PauseActor::Verwaltung || !str_starts_with((string) $pause->reason, self::BAN_MARKER)) {
                // Eine Pause aus anderem Anlass bleibt bestehen: Wer wegen
                // eines Verstosses eine einzelne Anzeige angehalten hat, will
                // sie nicht durch das Entsperren zurueckbekommen.
                continue;
            }

            $ziel = $pause->previousStatus->isPubliclyVisible() ? $pause->previousStatus : ListingStatus::Aktiv;
            $this->listings->resume($listing->id, $ziel);
            $this->indexer->indexListing($listing->id);
            ++$fortgesetzt;
        }

        return $fortgesetzt;
    }
}
