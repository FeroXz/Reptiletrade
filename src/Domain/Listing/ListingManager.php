<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

use Reptilienmarkt\Domain\Audit\AuditActorType;
use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Infra\Search\ListingIndexer;
use Reptilienmarkt\Infra\Storage\PrivateStorage;
use Reptilienmarkt\Infra\Storage\PublicImageStorage;

/**
 * Was mit einer veroeffentlichten Anzeige noch passieren darf: bearbeiten,
 * pausieren, fortsetzen, loeschen.
 *
 * Drei Regeln tragen das Ganze:
 *
 * 1. **Der Suchindex folgt dem Status.** Jede Zustandsaenderung nimmt die
 *    Anzeige aus dem Index oder legt sie hinein. Ein pausierter Eintrag, der im
 *    Volltext stehen bleibt, ist schlimmer als gar keine Pause — der Kaeufer
 *    findet ihn und laeuft ins Leere.
 *
 * 2. **Eine Pause der Verwaltung hebt der Anbieter nicht auf.** Sonst waere die
 *    Massnahme einen Klick wert.
 *
 * 3. **Geloescht wird nur, was allein dem Anbieter gehoert.** Haengen
 *    Gespraeche oder Bewertungen an der Anzeige, gehoeren die auch der
 *    Gegenseite; dann wird archiviert statt geloescht. Dieselbe Abwaegung wie
 *    bei der Kontoloeschung in Phase 7.
 */
final readonly class ListingManager
{
    public function __construct(
        private ListingRepository $listings,
        private ListingMediaRepository $media,
        private LegalDocumentRepository $legalDocuments,
        private ListingWizard $wizard,
        private ListingIndexer $indexer,
        private PublicImageStorage $images,
        private PrivateStorage $privateStorage,
        private AuditLog $audit,
    ) {}

    // ----------------------------------------------------------- Bearbeiten

    /**
     * Uebernimmt die geaenderten Felder und haelt Suchindex und Rechtspruefung
     * nach.
     *
     * Bleibt die Anzeige nach der Aenderung rechtlich zulaessig, bleibt sie
     * online. Wird sie es nicht — etwa weil eine Angabe entfernt wurde, die
     * eine Regel verlangt —, geht sie zurueck in die Pruefung statt weiter zu
     * laufen. Eine Aenderung darf keine Luecke in die Pruefung reissen.
     *
     * @param array<string, scalar|null> $changed Nur zur Protokollierung
     *
     * @throws ListingManagementException
     */
    public function update(Listing $updated, Listing $before, User $editor, array $changed = []): ListingStatus
    {
        $id = $updated->id ?? 0;

        if (!$before->belongsTo($editor->id ?? 0)) {
            throw new ListingManagementException('Diese Anzeige gehört dir nicht.');
        }

        if (!$before->status->isEditable()) {
            throw new ListingManagementException(\sprintf(
                'Eine Anzeige im Zustand "%s" lässt sich nicht mehr bearbeiten.',
                $before->status->label(),
            ));
        }

        $this->listings->save($updated);
        $this->listings->recordEdit($id, $editor->id ?? 0, $changed);

        $status = $updated->status;

        // Nur veroeffentlichte Anzeigen werden neu bewertet. Ein Entwurf wird
        // ohnehin erst beim Veroeffentlichen geprueft.
        if ($status === ListingStatus::Aktiv || $status === ListingStatus::Reserviert) {
            $decision = $this->wizard->evaluate($updated, $editor);

            if ($decision->blocked || $decision->requiresReview) {
                $this->listings->updateStatus($id, ListingStatus::Pruefung);
                $this->indexer->removeListing($id);
                $status = ListingStatus::Pruefung;
            } else {
                $this->indexer->indexListing($id);
            }
        }

        $this->audit->record(new AuditEntry(
            'listing.edited',
            'listing',
            $id,
            ['felder' => array_keys($changed), 'status' => $status->value],
            $editor->id,
        ));

        return $status;
    }

    // -------------------------------------------------------------- Pause

    /**
     * @throws ListingManagementException
     */
    public function pause(Listing $listing, User $user): void
    {
        if (!$listing->belongsTo($user->id ?? 0)) {
            throw new ListingManagementException('Diese Anzeige gehört dir nicht.');
        }

        $this->applyPause($listing, PauseActor::Anbieter, null, $user);
    }

    /**
     * @throws ListingManagementException
     */
    public function pauseByAdmin(Listing $listing, User $admin, string $reason): void
    {
        $this->requireAdmin($admin);
        $this->applyPause($listing, PauseActor::Verwaltung, trim($reason) === '' ? null : trim($reason), $admin);
    }

    /**
     * @throws ListingManagementException
     */
    public function resume(Listing $listing, User $user): void
    {
        if (!$listing->belongsTo($user->id ?? 0)) {
            throw new ListingManagementException('Diese Anzeige gehört dir nicht.');
        }

        $state = $this->pauseStateOrFail($listing);

        if (!$state->actor->mayBeResumedByOwner()) {
            throw new ListingManagementException(
                'Diese Anzeige wurde von der Verwaltung pausiert. Nur die Verwaltung kann sie wieder freigeben.',
            );
        }

        $this->applyResume($listing, $state, $user, false);
    }

    /**
     * @throws ListingManagementException
     */
    public function resumeByAdmin(Listing $listing, User $admin): void
    {
        $this->requireAdmin($admin);
        $this->applyResume($listing, $this->pauseStateOrFail($listing), $admin, true);
    }

    // ------------------------------------------------------------ Loeschen

    /**
     * Loescht die Anzeige — oder archiviert sie, wenn Gespraeche oder
     * Bewertungen daran haengen.
     *
     * @throws ListingManagementException
     */
    public function delete(Listing $listing, User $user): DeletionOutcome
    {
        if (!$listing->belongsTo($user->id ?? 0)) {
            throw new ListingManagementException('Diese Anzeige gehört dir nicht.');
        }

        $id = $listing->id ?? 0;
        $gespraeche = $this->listings->conversationCount($id);
        $bewertungen = $this->listings->reviewCount($id);
        $archivieren = $gespraeche > 0 || $bewertungen > 0;

        // Dateien in beiden Faellen weg: Wer loeschen will, will seine Bilder
        // nicht weiter auf einem fremden Server wissen.
        $dateien = $this->removeFiles($id);
        $this->indexer->removeListing($id);

        if ($archivieren) {
            $this->listings->updateStatus($id, ListingStatus::Abgelaufen);
        } else {
            $this->listings->delete($id);
        }

        $this->audit->record(new AuditEntry(
            $archivieren ? 'listing.archived_by_owner' : 'listing.deleted_by_owner',
            'listing',
            $id,
            ['dateien' => $dateien, 'gespraeche' => $gespraeche, 'bewertungen' => $bewertungen],
            $user->id,
        ));

        return new DeletionOutcome($archivieren, $dateien, $gespraeche, $bewertungen);
    }

    // -------------------------------------------------------- Hilfsmittel

    public function pauseState(Listing $listing): ?PauseState
    {
        return $listing->status === ListingStatus::Pausiert
            ? $this->listings->pauseState($listing->id ?? 0)
            : null;
    }

    /**
     * @throws ListingManagementException
     */
    private function applyPause(Listing $listing, PauseActor $actor, ?string $reason, User $actorUser): void
    {
        if (!$listing->status->isPausable()) {
            throw new ListingManagementException(\sprintf(
                'Eine Anzeige im Zustand "%s" lässt sich nicht pausieren.',
                $listing->status->label(),
            ));
        }

        $id = $listing->id ?? 0;

        $this->listings->pause($id, $actor, $reason, $listing->status);

        // Zuerst die Datenbank, dann der Index: Bleibt der Index stehen, ist er
        // beim naechsten Lauf von bin/reindex.php wieder richtig. Andersherum
        // waere die Anzeige unauffindbar, obwohl sie laeuft.
        $this->indexer->removeListing($id);

        $this->audit->record(new AuditEntry(
            $actor === PauseActor::Verwaltung ? 'listing.paused_by_admin' : 'listing.paused',
            'listing',
            $id,
            ['grund' => $reason, 'vorher' => $listing->status->value],
            $actorUser->id,
            $actor === PauseActor::Verwaltung ? AuditActorType::Admin : AuditActorType::User,
        ));
    }

    private function applyResume(Listing $listing, PauseState $state, User $actorUser, bool $byAdmin): void
    {
        $id = $listing->id ?? 0;
        $ziel = $state->previousStatus->isPubliclyVisible() ? $state->previousStatus : ListingStatus::Aktiv;

        $this->listings->resume($id, $ziel);
        $this->indexer->indexListing($id);

        $this->audit->record(new AuditEntry(
            $byAdmin ? 'listing.resumed_by_admin' : 'listing.resumed',
            'listing',
            $id,
            ['status' => $ziel->value],
            $actorUser->id,
            $byAdmin ? AuditActorType::Admin : AuditActorType::User,
        ));
    }

    /**
     * @throws ListingManagementException
     */
    private function pauseStateOrFail(Listing $listing): PauseState
    {
        if ($listing->status !== ListingStatus::Pausiert) {
            throw new ListingManagementException('Diese Anzeige ist nicht pausiert.');
        }

        $state = $this->listings->pauseState($listing->id ?? 0);

        if ($state === null) {
            throw new ListingManagementException('Zu dieser Pause fehlen die Angaben.');
        }

        return $state;
    }

    /**
     * @throws ListingManagementException
     */
    private function requireAdmin(User $user): void
    {
        if ($user->role !== Role::Admin) {
            throw new ListingManagementException('Dafür fehlt die Berechtigung.');
        }
    }

    private function removeFiles(int $listingId): int
    {
        $entfernt = 0;

        foreach ($this->media->forListing($listingId) as $item) {
            if ($item->isImage()) {
                $this->images->delete($item->path);
                ++$entfernt;
            }

            $this->media->delete($item->id);
        }

        foreach ($this->legalDocuments->forListing($listingId) as $document) {
            if ($document->privatePath !== null && $document->privatePath !== '') {
                $this->privateStorage->delete($document->privatePath);
                ++$entfernt;
            }
        }

        return $entfernt;
    }
}
