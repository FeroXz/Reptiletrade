<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Geo\PostalCodeRepository;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Domain\User\UserRepository;
use Reptilienmarkt\Legal\LegalContext;
use Reptilienmarkt\Legal\LegalDecision;
use Reptilienmarkt\Legal\LegalDocumentInput;
use Reptilienmarkt\Legal\LegalGuard;
use Reptilienmarkt\Legal\SellerProfile;
use Reptilienmarkt\Support\Clock;
use RuntimeException;

/**
 * Fachlogik des Anzeigenassistenten: Fortschritt bestimmen, Rechtsprüfung
 * anstossen, veroeffentlichen.
 *
 * Bewusst framework-frei — der Controller uebersetzt nur HTTP in Aufrufe.
 */
final readonly class ListingWizard
{
    /**
     * Laufzeit einer kostenlosen Anzeige.
     */
    public const int FREE_RUNTIME_DAYS = 60;

    public function __construct(
        private ListingRepository $listings,
        private ListingMediaRepository $media,
        private LegalDocumentRepository $legalDocuments,
        private SpeciesRepository $species,
        private PostalCodeRepository $postalCodes,
        private UserRepository $users,
        private LegalGuard $guard,
        private GeneticsCalculator $genetics,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    /**
     * Der erste Schritt, der noch nicht abgeschlossen ist.
     */
    public function currentStep(Listing $listing): WizardStep
    {
        if ($listing->title === '' && $listing->description === '') {
            return WizardStep::Details;
        }

        return WizardStep::Vorschau;
    }

    /**
     * Schritte, die der Nutzer bereits erledigt hat — fuer die Fortschrittsanzeige.
     *
     * @return list<WizardStep>
     */
    public function completedSteps(Listing $listing): array
    {
        $completed = [WizardStep::TypUndArt];

        if ($this->listings->morphSelections($listing->id ?? 0) !== []) {
            $completed[] = WizardStep::Merkmale;
        }

        if ($listing->title !== '') {
            $completed[] = WizardStep::Details;
        }

        if ($this->media->countImages($listing->id ?? 0) > 0) {
            $completed[] = WizardStep::Bilder;
        }

        if ($this->legalDocuments->forListing($listing->id ?? 0) !== [] || $listing->legalConfirmations !== []) {
            $completed[] = WizardStep::Nachweise;
        }

        if ($listing->hasLocation()) {
            $completed[] = WizardStep::PreisUndStandort;
        }

        return $completed;
    }

    /**
     * Der Anzeige-String aus den ausgewaehlten Merkmalen, z. B. "Hypo Trans het Zero".
     */
    public function morphString(int $listingId): string
    {
        return $this->genetics->morphString($this->listings->morphSelections($listingId));
    }

    public function genotype(int $listingId): string
    {
        return $this->genetics->genotype($this->listings->morphSelections($listingId));
    }

    /**
     * @return list<string>
     */
    public function geneticsWarnings(int $listingId): array
    {
        return $this->genetics->warnings($this->listings->morphSelections($listingId));
    }

    /**
     * Baut den Sachverhalt fuer die Rechts-Engine zusammen.
     */
    public function legalContext(Listing $listing, User $user): LegalContext
    {
        $species = $this->species->findById($listing->speciesId);

        if ($species === null) {
            throw new RuntimeException('Zur Anzeige fehlt die Art.');
        }

        $documents = [];
        foreach ($this->legalDocuments->forListing($listing->id ?? 0) as $record) {
            $documents[$record->docType->value] = new LegalDocumentInput(
                $record->docType,
                $record->referenceNumber,
                $record->issuingAuthority,
                $record->issueDate,
                $record->hasFile(),
            );
        }

        $statistics = $this->users->salesStatistics($user->id ?? 0);

        return new LegalContext(
            $species,
            $listing->type,
            $listing->country ?? Country::De,
            $listing->handover,
            new SellerProfile(
                $statistics['aktive_anzeigen'],
                $statistics['verkaeufe_12_monate'],
                $user->isCommercial,
                $user->erlaubnis11Number,
                $user->hasImprint(),
            ),
            $this->regionFor($listing),
            $listing->hatchDate,
            $listing->weightG,
            $listing->cbStatus,
            $documents,
            $listing->legalConfirmations,
        );
    }

    public function evaluate(Listing $listing, User $user): LegalDecision
    {
        return $this->guard->evaluate($this->legalContext($listing, $user));
    }

    /**
     * @return list<string>
     */
    public function requiredLegalFields(Listing $listing, User $user): array
    {
        return $this->guard->requiredFields($this->legalContext($listing, $user));
    }

    /**
     * Veroeffentlicht die Anzeige, sofern die Rechts-Engine es zulaesst.
     *
     * Jede Entscheidung landet im Audit-Trail — auch die ablehnende.
     */
    public function publish(Listing $listing, User $user, ?string $ipAddress = null): PublishResult
    {
        $errors = $this->completenessErrors($listing);
        $decision = $this->evaluate($listing, $user);

        if ($errors !== [] || $decision->blocked) {
            $this->audit->record(new AuditEntry(
                'listing.publish_blocked',
                'listing',
                $listing->id,
                $decision->auditPayload() + ['vollstaendigkeit' => $errors],
                $user->id,
                ipAddress: $ipAddress,
            ));

            return new PublishResult(false, $listing->status, $decision, $errors);
        }

        $status = $decision->requiresReview ? ListingStatus::Pruefung : ListingStatus::Aktiv;

        $this->listings->save(new Listing(
            $listing->id,
            $listing->userId,
            $listing->type,
            $listing->speciesId,
            $listing->title,
            $listing->description,
            $listing->priceCents,
            $listing->currency,
            $listing->negotiable,
            $listing->tradeWanted,
            $listing->sex,
            $listing->hatchDate,
            $listing->weightG,
            $listing->countAvailable,
            $listing->cbStatus,
            $status,
            $listing->postalCode,
            $listing->country,
            $listing->latitude,
            $listing->longitude,
            $listing->handover,
            $listing->legalConfirmations,
            $this->clock->now()->modify(\sprintf('+%d days', self::FREE_RUNTIME_DAYS)),
        ));

        $this->listings->updateStatus($listing->id ?? 0, $status);

        $this->audit->record(new AuditEntry(
            'listing.published',
            'listing',
            $listing->id,
            $decision->auditPayload() + ['status' => $status->value],
            $user->id,
            ipAddress: $ipAddress,
        ));

        return new PublishResult(true, $status, $decision);
    }

    /**
     * Pflichtangaben unabhaengig vom Recht — Titel, Standort, Preis.
     *
     * @return list<string>
     */
    public function completenessErrors(Listing $listing): array
    {
        $errors = [];

        if (mb_strlen(trim($listing->title)) < 5) {
            $errors[] = 'Der Titel braucht mindestens fünf Zeichen.';
        }

        if (!$listing->hasLocation()) {
            $errors[] = 'Bitte gib Postleitzahl und Land an.';
        }

        if ($listing->type->requiresPrice() && $listing->priceCents === null) {
            $errors[] = 'Für diesen Anzeigentyp ist ein Preis nötig.';
        }

        if ($listing->type === ListingType::Tausch && ($listing->tradeWanted === null || trim($listing->tradeWanted) === '')) {
            $errors[] = 'Bitte beschreibe, wogegen du tauschen möchtest.';
        }

        if ($listing->type->describesAnimalOnOffer() && $this->media->countImages($listing->id ?? 0) === 0) {
            $errors[] = 'Mindestens ein Bild ist nötig.';
        }

        return $errors;
    }

    /**
     * Bundesland bzw. Kanton zur Postleitzahl — Eingangsgroesse der Gefahrtierregel.
     */
    private function regionFor(Listing $listing): ?string
    {
        if ($listing->postalCode === null || $listing->country === null) {
            return null;
        }

        return $this->postalCodes->find($listing->country, $listing->postalCode)?->admin1;
    }
}
