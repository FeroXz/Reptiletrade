<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Geo\PostalCodeRepository;
use Reptilienmarkt\Domain\Listing\CbStatus;
use Reptilienmarkt\Domain\Listing\Handover;
use Reptilienmarkt\Domain\Listing\Listing;
use Reptilienmarkt\Domain\Listing\ListingManagementException;
use Reptilienmarkt\Domain\Listing\ListingManager;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Twig\Environment;

/**
 * Eine veroeffentlichte Anzeige bearbeiten, pausieren, fortsetzen, loeschen.
 *
 * Getrennt vom Assistenten, weil es ein anderer Vorgang ist: Der Assistent
 * fuehrt in sieben Schritten zu einer vollstaendigen Anzeige, hier wird an
 * einer fertigen etwas geaendert. Deshalb ein Formular statt einer Strecke.
 *
 * Art und Angebotstyp bleiben fest. Sie zu aendern hiesse, die Rechtspruefung,
 * die Merkmalsauswahl und die hochgeladenen Nachweise auf eine andere Grundlage
 * zu stellen — dafuer gibt es eine neue Anzeige.
 */
final readonly class ListingManagementController
{
    public function __construct(
        private ListingRepository $listings,
        private ListingManager $manager,
        private SpeciesRepository $species,
        private PostalCodeRepository $postalCodes,
        private Viewer $currentUser,
        private SessionManager $session,
        private Environment $twig,
    ) {}

    public function edit(Request $request): Response
    {
        $user = $this->currentUser->require();
        $listing = $this->requireOwn($request, $user);

        if (!$listing->status->isEditable()) {
            $this->session->flash('fehler', \sprintf(
                'Eine Anzeige im Zustand "%s" lässt sich nicht mehr bearbeiten.',
                $listing->status->label(),
            ));

            return Response::redirect('/meine-anzeigen/');
        }

        return Response::html($this->twig->render('anzeige/bearbeiten.html.twig', $this->viewData($listing)));
    }

    public function update(Request $request): Response
    {
        $user = $this->currentUser->require();
        $listing = $this->requireOwn($request, $user);
        $this->guardCsrf($request);

        $fehler = $this->validate($request);

        if ($fehler !== []) {
            return Response::html($this->twig->render(
                'anzeige/bearbeiten.html.twig',
                $this->viewData($listing) + ['feldfehler' => $fehler],
            ), 422);
        }

        $geaendert = $this->apply($listing, $request);

        try {
            $status = $this->manager->update($geaendert, $listing, $user, $this->changes($listing, $geaendert));
        } catch (ListingManagementException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/meine-anzeigen/');
        }

        $this->session->flash('erfolg', $status === ListingStatus::Pruefung
            ? 'Die Änderung ist gespeichert. Weil sich dadurch die rechtliche Bewertung ändert, '
                . 'schauen wir noch einmal drüber — die Anzeige ist so lange offline.'
            : 'Die Änderung ist gespeichert.');

        return Response::redirect(\sprintf('/anzeige/%d/', $listing->id));
    }

    public function pause(Request $request): Response
    {
        $user = $this->currentUser->require();
        $listing = $this->requireOwn($request, $user);
        $this->guardCsrf($request);

        try {
            $this->manager->pause($listing, $user);
        } catch (ListingManagementException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/meine-anzeigen/');
        }

        $this->session->flash('erfolg', 'Die Anzeige ist pausiert und für Käufer nicht mehr sichtbar.');

        return Response::redirect('/meine-anzeigen/');
    }

    public function resume(Request $request): Response
    {
        $user = $this->currentUser->require();
        $listing = $this->requireOwn($request, $user);
        $this->guardCsrf($request);

        try {
            $this->manager->resume($listing, $user);
        } catch (ListingManagementException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/meine-anzeigen/');
        }

        $this->session->flash('erfolg', 'Die Anzeige läuft wieder.');

        return Response::redirect('/meine-anzeigen/');
    }

    public function delete(Request $request): Response
    {
        $user = $this->currentUser->require();
        $listing = $this->requireOwn($request, $user);
        $this->guardCsrf($request);

        // Dasselbe Wort wie bei der Kontoloeschung: Ein Fehlklick soll nicht
        // reichen, und zwei verschiedene Bestaetigungsworte waeren eine Falle.
        $bestaetigung = $request->body['bestaetigung'] ?? '';
        $bestaetigung = \is_string($bestaetigung) ? mb_strtoupper(trim($bestaetigung)) : '';

        if (!\in_array($bestaetigung, ['LÖSCHEN', 'LOESCHEN'], true)) {
            $this->session->flash('fehler', 'Bitte tippe LÖSCHEN in das Bestätigungsfeld.');

            return Response::redirect(\sprintf('/anzeige/%d/bearbeiten', $listing->id));
        }

        try {
            $ergebnis = $this->manager->delete($listing, $user);
        } catch (ListingManagementException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/meine-anzeigen/');
        }

        $this->session->flash($ergebnis->archived ? 'hinweis' : 'erfolg', $ergebnis->message());

        return Response::redirect('/meine-anzeigen/');
    }

    // -------------------------------------------------------- Hilfsmittel

    /**
     * @return array<string, mixed>
     */
    private function viewData(Listing $listing): array
    {
        return [
            'listing' => $listing,
            'art' => $this->species->findById($listing->speciesId),
            'pause' => $this->manager->pauseState($listing),
            'gespraeche' => $this->listings->conversationCount($listing->id ?? 0),
            'bewertungen' => $this->listings->reviewCount($listing->id ?? 0),
            'bearbeitungen' => $this->listings->editCount($listing->id ?? 0),
            'geschlechter' => Sex::cases(),
            'herkuenfte' => CbStatus::cases(),
            'uebergaben' => Handover::cases(),
            'laender' => Country::cases(),
            'feldfehler' => [],
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ];
    }

    /**
     * Uebernimmt die Felder, die nach der Veroeffentlichung noch aenderbar
     * sind. Art, Angebotstyp und Merkmale sind bewusst nicht dabei.
     */
    private function apply(Listing $listing, Request $request): Listing
    {
        $preis = str_replace(',', '.', $this->input($request, 'preis'));
        $plz = $this->input($request, 'plz');
        $land = Country::tryFrom($this->input($request, 'land')) ?? $listing->country ?? Country::De;
        $ort = $plz === '' ? null : $this->postalCodes->find($land, $plz);
        $gewicht = $this->input($request, 'gewicht');
        $anzahl = (int) $this->input($request, 'anzahl');

        return new Listing(
            $listing->id,
            $listing->userId,
            $listing->type,
            $listing->speciesId,
            mb_substr($this->input($request, 'titel'), 0, 120),
            mb_substr($this->input($request, 'beschreibung'), 0, 5000),
            is_numeric($preis) ? (int) round((float) $preis * 100) : null,
            $land->currency(),
            $this->input($request, 'verhandelbar') !== '',
            $this->input($request, 'tauschwunsch') === '' ? null : $this->input($request, 'tauschwunsch'),
            Sex::tryFrom($this->input($request, 'geschlecht')) ?? $listing->sex,
            $listing->hatchDate,
            $gewicht === '' ? null : max(1, (int) $gewicht),
            $anzahl > 0 ? $anzahl : 1,
            CbStatus::tryFrom($this->input($request, 'herkunft')) ?? $listing->cbStatus,
            $listing->status,
            // Unbekannte Postleitzahl: Der bisherige Standort bleibt stehen,
            // statt die Anzeige ortlos zu machen.
            $ort === null ? $listing->postalCode : $ort->postalCode,
            $ort === null ? $listing->country : $land,
            $ort === null ? $listing->latitude : $ort->coordinates->latitude,
            $ort === null ? $listing->longitude : $ort->coordinates->longitude,
            Handover::tryFrom($this->input($request, 'uebergabe')) ?? $listing->handover,
            $listing->legalConfirmations,
            $listing->expiresAt,
        );
    }

    /**
     * Was sich geaendert hat — mit dem alten Wert, denn der ist beim
     * Nachvollziehen der interessante.
     *
     * @return array<string, scalar|null>
     */
    private function changes(Listing $vorher, Listing $nachher): array
    {
        $geaendert = [];

        foreach ([
            'titel' => [$vorher->title, $nachher->title],
            'beschreibung' => [$vorher->description, $nachher->description],
            'preis_cent' => [$vorher->priceCents, $nachher->priceCents],
            'verhandelbar' => [$vorher->negotiable, $nachher->negotiable],
            'tauschwunsch' => [$vorher->tradeWanted, $nachher->tradeWanted],
            'geschlecht' => [$vorher->sex->value, $nachher->sex->value],
            'gewicht_g' => [$vorher->weightG, $nachher->weightG],
            'anzahl' => [$vorher->countAvailable, $nachher->countAvailable],
            'herkunft' => [$vorher->cbStatus->value, $nachher->cbStatus->value],
            'plz' => [$vorher->postalCode, $nachher->postalCode],
            'land' => [$vorher->country?->value, $nachher->country?->value],
            'uebergabe' => [$vorher->handover->value, $nachher->handover->value],
        ] as $feld => [$alt, $neu]) {
            if ($alt !== $neu) {
                $geaendert[$feld] = \is_bool($alt) ? ($alt ? '1' : '0') : $alt;
            }
        }

        return $geaendert;
    }

    /**
     * @return array<string, string>
     */
    private function validate(Request $request): array
    {
        $fehler = [];

        if (mb_strlen(trim($this->input($request, 'titel'))) < 5) {
            $fehler['titel'] = 'Der Titel braucht mindestens fünf Zeichen.';
        }

        if (mb_strlen(trim($this->input($request, 'beschreibung'))) < 20) {
            $fehler['beschreibung'] = 'Die Beschreibung braucht mindestens 20 Zeichen.';
        }

        $preis = str_replace(',', '.', $this->input($request, 'preis'));

        if ($preis !== '' && (!is_numeric($preis) || (float) $preis < 0)) {
            $fehler['preis'] = 'Der Preis muss eine Zahl ab 0 sein.';
        }

        return $fehler;
    }

    private function requireOwn(Request $request, User $user): Listing
    {
        $id = $request->attribute('id');

        if ($id === null || !ctype_digit($id)) {
            throw HttpException::notFound('Anzeige nicht gefunden.');
        }

        $listing = $this->listings->findById((int) $id);

        // 404 statt 403 — eine fremde Anzeige soll sich nicht durch eine
        // Ablehnung verraten.
        if ($listing === null || !$listing->belongsTo($user->id ?? 0)) {
            throw HttpException::notFound('Anzeige nicht gefunden.');
        }

        return $listing;
    }

    private function input(Request $request, string $name): string
    {
        $value = $request->body[$name] ?? '';

        return \is_string($value) ? trim($value) : '';
    }

    private function guardCsrf(Request $request): void
    {
        $this->session->assertCsrf($request);
    }
}
