<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use DateTimeImmutable;
use Exception;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Geo\PostalCodeRepository;
use Reptilienmarkt\Domain\Listing\CbStatus;
use Reptilienmarkt\Domain\Listing\Handover;
use Reptilienmarkt\Domain\Listing\LegalDocType;
use Reptilienmarkt\Domain\Listing\LegalDocumentRecord;
use Reptilienmarkt\Domain\Listing\LegalDocumentRepository;
use Reptilienmarkt\Domain\Listing\Listing;
use Reptilienmarkt\Domain\Listing\ListingMediaRepository;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\ListingWizard;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Listing\WizardStep;
use Reptilienmarkt\Domain\Listing\Zygosity;
use Reptilienmarkt\Domain\Species\MorphRepository;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Infra\Search\ListingIndexer;
use Twig\Environment;

/**
 * Der mehrstufige Anzeigenassistent.
 *
 * Jeder Schritt speichert sofort in den Entwurf — es gibt keinen Zustand, der
 * nur im Browser lebt. Wer den Assistenten verlaesst, findet den Entwurf beim
 * naechsten Aufruf an derselben Stelle wieder.
 */
final readonly class ListingWizardController
{
    public function __construct(
        private ListingRepository $listings,
        private ListingMediaRepository $media,
        private LegalDocumentRepository $legalDocuments,
        private SpeciesRepository $species,
        private MorphRepository $morphs,
        private PostalCodeRepository $postalCodes,
        private ListingWizard $wizard,
        private RateLimiter $rateLimiter,
        private ListingIndexer $indexer,
        private Viewer $currentUser,
        private SessionManager $session,
        private Environment $twig,
    ) {}

    /**
     * Legt einen Entwurf an oder nimmt den letzten wieder auf.
     */
    public function start(Request $request): Response
    {
        $user = $this->currentUser->require();

        $draft = $this->listings->findLatestDraft($user->id ?? 0);
        if ($draft !== null) {
            return Response::redirect(\sprintf('/anzeige/%d/schritt/1', $draft->id));
        }

        return Response::html($this->twig->render('anzeige/start.html.twig', [
            'csrf' => $this->session->csrfToken(),
            'typen' => ListingType::cases(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    /**
     * Schritt 1 legt den Entwurf an: ohne Typ und Art gibt es nichts zu speichern.
     */
    public function create(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->guardCsrf($request);

        $type = ListingType::tryFrom($this->input($request, 'typ'));
        $speciesId = (int) $this->input($request, 'art_id');
        $species = $speciesId > 0 ? $this->species->findById($speciesId) : null;

        if ($type === null || $species === null) {
            $this->session->flash('fehler', 'Bitte wähle Anzeigenart und Tierart aus.');

            return Response::redirect('/anzeige/neu');
        }

        // Gezaehlt wird das Anlegen, nicht das Veroeffentlichen: Ein Skript
        // muesste sonst nur Entwuerfe erzeugen, um die Tabelle zu fluten.
        if (!$this->rateLimiter->attempt('anzeige.konto', (string) ($user->id ?? 0))->allowed) {
            $this->session->flash('fehler', 'Du hast heute schon viele Anzeigen begonnen. Bitte mach morgen weiter.');

            return Response::redirect('/meine-anzeigen/');
        }

        $id = $this->listings->create(new Listing(null, $user->id ?? 0, $type, $speciesId));

        return Response::redirect(\sprintf('/anzeige/%d/schritt/2', $id));
    }

    public function step(Request $request): Response
    {
        $user = $this->currentUser->require();
        $listing = $this->requireOwnDraft($request, $user);
        $step = $this->requireStep($request);

        return Response::html($this->twig->render('anzeige/assistent.html.twig', $this->viewData($listing, $user, $step)));
    }

    public function save(Request $request): Response
    {
        $user = $this->currentUser->require();
        $listing = $this->requireOwnDraft($request, $user);
        $step = $this->requireStep($request);
        $this->guardCsrf($request);

        $updated = $this->applyStep($listing, $step, $request);
        $this->listings->save($updated);

        if ($step === WizardStep::Merkmale) {
            $this->saveMorphs($listing->id ?? 0, $request);
        }

        if ($step === WizardStep::Nachweise) {
            $this->saveLegalFields($listing->id ?? 0, $request);
        }

        $next = $step->next();

        return Response::redirect(\sprintf('/anzeige/%d/schritt/%d', $listing->id, ($next ?? $step)->value));
    }

    /**
     * Zwischenspeichern ohne Seitenwechsel. Antwortet mit JSON, damit das
     * Formular im Browser nur eine kurze Bestaetigung anzeigen muss.
     */
    public function autosave(Request $request): Response
    {
        $user = $this->currentUser->require();
        $listing = $this->requireOwnDraft($request, $user);
        $step = $this->requireStep($request);
        $this->guardCsrf($request);

        $this->listings->save($this->applyStep($listing, $step, $request));

        if ($step === WizardStep::Merkmale) {
            $this->saveMorphs($listing->id ?? 0, $request);
        }

        return Response::json([
            'gespeichert' => true,
            'zeitpunkt' => gmdate('c'),
            'morph_string' => $this->wizard->morphString($listing->id ?? 0),
        ]);
    }

    public function publish(Request $request): Response
    {
        $user = $this->currentUser->require();
        $listing = $this->requireOwnDraft($request, $user);
        $this->guardCsrf($request);

        $result = $this->wizard->publish($listing, $user, $request->clientIp);

        if (!$result->published) {
            $this->session->flash('fehler', $result->message());

            return Response::redirect(\sprintf('/anzeige/%d/schritt/7', $listing->id));
        }

        $this->indexer->indexListing($listing->id ?? 0);
        $this->session->flash('erfolg', $result->message());

        return Response::redirect(\sprintf('/anzeige/%d/', $listing->id));
    }

    public function mine(Request $request): Response
    {
        $user = $this->currentUser->require();
        $anzeigen = $this->listings->forUser($user->id ?? 0);

        // Die Pausenangaben nur fuer die tatsaechlich pausierten Anzeigen
        // nachschlagen — sonst waere es eine Abfrage je Zeile.
        $pausen = [];
        foreach ($anzeigen as $anzeige) {
            if ($anzeige->status === ListingStatus::Pausiert && $anzeige->id !== null) {
                $pausen[$anzeige->id] = $this->listings->pauseState($anzeige->id);
            }
        }

        return Response::html($this->twig->render('anzeige/meine.html.twig', [
            'anzeigen' => $anzeigen,
            'pausen' => $pausen,
            'arten' => $this->speciesNames(),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(Listing $listing, User $user, WizardStep $step): array
    {
        $listingId = $listing->id ?? 0;
        $species = $this->species->findById($listing->speciesId);

        $data = [
            'listing' => $listing,
            'art' => $species,
            'schritt' => $step,
            'schritte' => WizardStep::cases(),
            'erledigt' => $this->wizard->completedSteps($listing),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
            'typen' => ListingType::cases(),
            'geschlechter' => Sex::cases(),
            'herkuenfte' => CbStatus::cases(),
            'uebergaben' => Handover::cases(),
            'laender' => Country::cases(),
            'morph_string' => $this->wizard->morphString($listingId),
            'genotyp' => $this->wizard->genotype($listingId),
            'genetik_warnungen' => $this->wizard->geneticsWarnings($listingId),
        ];

        if ($step === WizardStep::Merkmale) {
            $data['verfuegbare_morphs'] = $this->morphs->forSpecies($listing->speciesId);
            $data['gewaehlte_morphs'] = $this->selectedMorphMap($listingId);
            $data['zygositaeten'] = Zygosity::cases();
        }

        if ($step === WizardStep::Bilder) {
            $data['medien'] = $this->media->forListing($listingId);
            $data['max_bilder'] = 12;
        }

        if ($step === WizardStep::Nachweise) {
            $data['pflichtfelder'] = $this->wizard->requiredLegalFields($listing, $user);
            $data['nachweise'] = $this->legalDocuments->forListing($listingId);
            $data['nachweis_arten'] = LegalDocType::cases();
            $data['entscheidung'] = $this->wizard->evaluate($listing, $user);
        }

        if ($step === WizardStep::Vorschau) {
            $data['entscheidung'] = $this->wizard->evaluate($listing, $user);
            $data['vollstaendigkeit'] = $this->wizard->completenessErrors($listing);
            $data['medien'] = $this->media->forListing($listingId);
        }

        return $data;
    }

    private function applyStep(Listing $listing, WizardStep $step, Request $request): Listing
    {
        return match ($step) {
            WizardStep::TypUndArt => $this->withTypeAndSpecies($listing, $request),
            WizardStep::Details => $this->withDetails($listing, $request),
            WizardStep::PreisUndStandort => $this->withPriceAndLocation($listing, $request),
            default => $listing,
        };
    }

    private function withTypeAndSpecies(Listing $listing, Request $request): Listing
    {
        $type = ListingType::tryFrom($this->input($request, 'typ')) ?? $listing->type;
        $speciesId = (int) $this->input($request, 'art_id');

        if ($speciesId <= 0 || $this->species->findById($speciesId) === null) {
            $speciesId = $listing->speciesId;
        }

        return $this->copy($listing, ['type' => $type, 'speciesId' => $speciesId]);
    }

    private function withDetails(Listing $listing, Request $request): Listing
    {
        $hatchDate = $this->input($request, 'schlupfdatum');
        $weight = $this->input($request, 'gewicht');
        $count = (int) $this->input($request, 'anzahl');

        return $this->copy($listing, [
            'title' => mb_substr($this->input($request, 'titel'), 0, 120),
            'description' => mb_substr($this->input($request, 'beschreibung'), 0, 5000),
            'sex' => Sex::tryFrom($this->input($request, 'geschlecht')) ?? $listing->sex,
            'hatchDate' => $hatchDate === '' ? null : $this->parseDate($hatchDate),
            'weightG' => $weight === '' ? null : max(1, (int) $weight),
            'countAvailable' => $count > 0 ? $count : 1,
            'cbStatus' => CbStatus::tryFrom($this->input($request, 'herkunft')) ?? $listing->cbStatus,
        ]);
    }

    private function withPriceAndLocation(Listing $listing, Request $request): Listing
    {
        $priceInput = $this->input($request, 'preis');
        $postalCode = $this->input($request, 'plz');
        $country = Country::tryFrom($this->input($request, 'land')) ?? $listing->country ?? Country::De;

        $place = $postalCode === '' ? null : $this->postalCodes->find($country, $postalCode);

        return $this->copy($listing, [
            'priceCents' => Listing::priceCentsFromInput($priceInput),
            'currency' => $country->currency(),
            'negotiable' => $this->input($request, 'verhandelbar') !== '',
            'tradeWanted' => $this->input($request, 'tauschwunsch') === '' ? null : $this->input($request, 'tauschwunsch'),
            'handover' => Handover::tryFrom($this->input($request, 'uebergabe')) ?? $listing->handover,
            'postalCode' => $place?->postalCode,
            'country' => $place === null ? null : $country,
            'latitude' => $place?->coordinates->latitude,
            'longitude' => $place?->coordinates->longitude,
        ]);
    }

    private function saveMorphs(int $listingId, Request $request): void
    {
        /** @var mixed $raw */
        $raw = $request->body['morph'] ?? [];
        if (!\is_array($raw)) {
            return;
        }

        $selection = [];
        foreach ($raw as $morphId => $zygosity) {
            if (!is_numeric($morphId) || !\is_string($zygosity) || $zygosity === '') {
                continue;
            }

            $parsed = Zygosity::tryFrom($zygosity);
            if ($parsed !== null) {
                $selection[(int) $morphId] = $parsed;
            }
        }

        $this->listings->replaceMorphs($listingId, $selection);
    }

    /**
     * Schritt 5 speichert Referenznummern und Bestaetigungen. Die Dateien
     * kommen ueber den eigenen Upload-Endpunkt.
     */
    private function saveLegalFields(int $listingId, Request $request): void
    {
        $listing = $this->listings->findById($listingId);
        if ($listing === null) {
            return;
        }

        $confirmations = $listing->legalConfirmations;
        foreach (['meldung_bestaetigt', 'meldung_datum', 'kennzeichnung_art', 'kennzeichnung_nummer'] as $field) {
            $value = $this->input($request, $field);
            if ($value !== '') {
                $confirmations[$field] = $value;
            } else {
                unset($confirmations[$field]);
            }
        }

        $this->listings->save($this->copy($listing, ['legalConfirmations' => $confirmations]));

        foreach (LegalDocType::cases() as $docType) {
            $reference = $this->input($request, 'nachweis_' . $docType->value . '_nummer');
            $authority = $this->input($request, 'nachweis_' . $docType->value . '_behoerde');
            $issueDate = $this->input($request, 'nachweis_' . $docType->value . '_datum');

            if ($reference === '' && $authority === '' && $issueDate === '') {
                continue;
            }

            $existing = $this->legalDocuments->findByType($listingId, $docType);

            $this->legalDocuments->save(new LegalDocumentRecord(
                $existing?->id,
                $listingId,
                $docType,
                $reference === '' ? null : $reference,
                $authority === '' ? null : $authority,
                $issueDate === '' ? null : $this->parseDate($issueDate),
                $existing?->privatePath,
                $existing?->originalFilename,
                $existing?->mimeType,
                $existing?->byteSize,
            ));
        }
    }

    /**
     * @return array<int, string>
     */
    private function selectedMorphMap(int $listingId): array
    {
        $map = [];
        foreach ($this->listings->morphSelections($listingId) as $selection) {
            if ($selection->morph->id !== null) {
                $map[$selection->morph->id] = $selection->zygosity->value;
            }
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function speciesNames(): array
    {
        $names = [];
        foreach ($this->species->all() as $species) {
            if ($species->id !== null) {
                $names[$species->id] = $species->commonNameDe;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function copy(Listing $listing, array $changes): Listing
    {
        $values = [
            'type' => $listing->type,
            'speciesId' => $listing->speciesId,
            'title' => $listing->title,
            'description' => $listing->description,
            'priceCents' => $listing->priceCents,
            'currency' => $listing->currency,
            'negotiable' => $listing->negotiable,
            'tradeWanted' => $listing->tradeWanted,
            'sex' => $listing->sex,
            'hatchDate' => $listing->hatchDate,
            'weightG' => $listing->weightG,
            'countAvailable' => $listing->countAvailable,
            'cbStatus' => $listing->cbStatus,
            'postalCode' => $listing->postalCode,
            'country' => $listing->country,
            'latitude' => $listing->latitude,
            'longitude' => $listing->longitude,
            'handover' => $listing->handover,
            'legalConfirmations' => $listing->legalConfirmations,
        ];

        /** @var array<string, mixed> $values */
        $values = array_merge($values, $changes);

        return new Listing(
            $listing->id,
            $listing->userId,
            $values['type'],
            $values['speciesId'],
            $values['title'],
            $values['description'],
            $values['priceCents'],
            $values['currency'],
            $values['negotiable'],
            $values['tradeWanted'],
            $values['sex'],
            $values['hatchDate'],
            $values['weightG'],
            $values['countAvailable'],
            $values['cbStatus'],
            $listing->status,
            $values['postalCode'],
            $values['country'],
            $values['latitude'],
            $values['longitude'],
            $values['handover'],
            $values['legalConfirmations'],
            $listing->expiresAt,
        );
    }

    private function requireOwnDraft(Request $request, User $user): Listing
    {
        $id = $request->attribute('id');
        if ($id === null || !ctype_digit($id)) {
            throw HttpException::notFound('Anzeige nicht gefunden.');
        }

        $listing = $this->listings->findById((int) $id);

        // 404 statt 403: Ein fremder Entwurf soll nicht einmal in seiner
        // Existenz bestaetigt werden.
        if ($listing === null || !$listing->belongsTo($user->id ?? 0)) {
            throw HttpException::notFound('Anzeige nicht gefunden.');
        }

        if ($listing->status !== ListingStatus::Entwurf && $listing->status !== ListingStatus::Pruefung) {
            throw HttpException::forbidden('Diese Anzeige lässt sich nicht mehr im Assistenten bearbeiten.');
        }

        return $listing;
    }

    private function requireStep(Request $request): WizardStep
    {
        $value = $request->attribute('schritt');
        $step = $value !== null && ctype_digit($value) ? WizardStep::tryFrom((int) $value) : null;

        return $step ?? throw HttpException::notFound('Diesen Schritt gibt es nicht.');
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    private function guardCsrf(Request $request): void
    {
        $this->session->assertCsrf($request);
    }

    private function input(Request $request, string $name): string
    {
        $value = $request->body[$name] ?? '';

        return \is_string($value) ? trim($value) : '';
    }
}
