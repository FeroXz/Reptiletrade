<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Admin\CatalogException;
use Reptilienmarkt\Domain\Admin\DashboardService;
use Reptilienmarkt\Domain\Admin\SpeciesCatalogService;
use Reptilienmarkt\Domain\Job\JobRepository;
use Reptilienmarkt\Domain\Mail\MailOutboxRepository;
use Reptilienmarkt\Domain\Privacy\RetentionPolicy;
use Reptilienmarkt\Domain\Site\TextException;
use Reptilienmarkt\Domain\Site\UiTextService;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Legal\Disclaimer;
use Reptilienmarkt\Support\Translator;
use Twig\Environment;

/**
 * Admin-Dashboard, Artenverwaltung und Betriebsuebersicht.
 *
 * Zugang nur fuer Administration — Moderation reicht hier nicht: Wer den
 * Artenstamm aendert, aendert die Grundlage der Rechtspruefung.
 */
final readonly class AdminController
{
    public function __construct(
        private DashboardService $dashboard,
        private SpeciesCatalogService $catalog,
        private JobRepository $jobs,
        private MailOutboxRepository $outbox,
        private RetentionPolicy $retention,
        private UiTextService $texts,
        private Viewer $currentUser,
        private SessionManager $session,
        private Translator $translator,
        private Environment $twig,
    ) {}

    public function dashboard(Request $request): Response
    {
        $this->requireAdmin();

        return Response::html($this->twig->render('admin/dashboard.html.twig', [
            'kennzahlen' => $this->dashboard->stats(),
            'fehlgeschlagene_jobs' => $this->jobs->recentFailures(10),
            // Aufgegebene Mails scheitern nicht mehr ueber den Auftrag — der
            // waere sonst dauerhaft rot. Sie gehoeren trotzdem hierher: Eine
            // nicht zugestellte Bestaetigungsmail merkt sonst niemand.
            'fehlgeschlagene_mails' => $this->outbox->recentFailures(10),
            'fristen' => $this->retention->all(),
            'geaenderte_texte' => $this->texts->changedCount(),
            'disclaimer_titel' => Disclaimer::TITLE,
            'disclaimer_text' => Disclaimer::BODY,
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function catalog(Request $request): Response
    {
        $this->requireAdmin();

        return Response::html($this->twig->render('admin/artenstamm.html.twig', [
            'spalten_arten' => SpeciesCatalogService::SPECIES_COLUMNS,
            'spalten_morphs' => SpeciesCatalogService::MORPH_COLUMNS,
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
            'ergebnis' => $this->session->get('katalog_ergebnis'),
        ]));
    }

    public function exportCatalog(Request $request): Response
    {
        $this->requireAdmin();

        $art = $request->attribute('art') ?? 'arten';
        $format = $request->queryString('format', 'json') ?? 'json';

        [$inhalt, $typ, $name] = match ([$art, $format]) {
            ['arten', 'csv'] => [$this->catalog->exportSpeciesCsv(), 'text/csv', 'arten.csv'],
            ['arten', 'json'] => [$this->catalog->exportSpeciesJson(), 'application/json', 'arten.json'],
            ['morphs', 'csv'] => [$this->catalog->exportMorphsCsv(), 'text/csv', 'morphs.csv'],
            ['morphs', 'json'] => [$this->catalog->exportMorphsJson(), 'application/json', 'morphs.json'],
            default => throw HttpException::notFound('Unbekannter Export.'),
        };

        return new Response($inhalt, 200, [
            'content-type' => $typ . '; charset=utf-8',
            'content-disposition' => 'attachment; filename="' . $name . '"',
            'cache-control' => 'private, no-store',
        ]);
    }

    public function importCatalog(Request $request): Response
    {
        $admin = $this->requireAdmin();
        $this->guardCsrf($request);

        $art = $request->attribute('art') ?? 'arten';
        $datei = $request->file('datei');
        $probelauf = ($request->body['probelauf'] ?? '') !== '';

        if ($datei === null || !$datei->isOk()) {
            $this->session->flash('fehler', $datei?->errorMessage() ?? 'Es wurde keine Datei ausgewählt.');

            return Response::redirect('/admin/artenstamm');
        }

        $inhalt = (string) file_get_contents($datei->temporaryPath);
        $format = str_ends_with(strtolower($datei->clientFilename), '.csv') ? 'csv' : 'json';

        try {
            $ergebnis = $art === 'morphs'
                ? $this->catalog->importMorphs($inhalt, $format, $admin->id, $probelauf)
                : $this->catalog->importSpecies($inhalt, $format, $admin->id, $probelauf);
        } catch (CatalogException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/admin/artenstamm');
        }

        // Die Fehlerliste gehoert in die naechste Ansicht — eine Flash-Meldung
        // mit vierzig Zeilen liest niemand.
        $this->session->put('katalog_ergebnis', [
            'meldung' => $ergebnis->message(),
            'fehler' => $ergebnis->errors,
            'erfolg' => $ergebnis->isSuccessful(),
        ]);

        $this->session->flash($ergebnis->isSuccessful() ? 'erfolg' : 'fehler', $ergebnis->message());

        return Response::redirect('/admin/artenstamm');
    }

    /**
     * GET /admin/texte
     *
     * Alle Oberflaechentexte auf einer Seite: links der ausgelieferte Text,
     * rechts der, den die Besucher sehen. Gegliedert nach Bereichen und
     * durchsuchbar — 274 Texte in einer Liste findet niemand.
     */
    public function texts(Request $request): Response
    {
        $this->requireAdmin();

        $suche = $request->queryString('q');
        $bereich = $request->queryString('bereich');

        return Response::html($this->twig->render('admin/texte.html.twig', [
            'texte' => $this->texts->all($suche, $bereich),
            'bereiche' => $this->texts->sections(),
            'suche' => $suche ?? '',
            'bereich' => $bereich ?? '',
            'geaendert' => $this->texts->changedCount(),
            'max_laenge' => UiTextService::MAX_LENGTH,
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    /**
     * POST /admin/texte
     *
     * Ein Formular, zwei Aktionen: Speichern schreibt alle geaenderten Felder
     * der aktuellen Ansicht, der Zuruecksetzen-Knopf einer Zeile traegt ihren
     * Schluessel. Ohne Javascript, mit einer einzigen Absendung.
     */
    public function saveTexts(Request $request): Response
    {
        $admin = $this->requireAdmin();
        $this->guardCsrf($request);

        $ziel = '/admin/texte' . $this->filterQuery($request);
        $zuruecksetzen = $request->body['zuruecksetzen'] ?? null;

        if (\is_string($zuruecksetzen) && $zuruecksetzen !== '') {
            $this->texts->reset($zuruecksetzen, $admin->id);
            $this->session->flash('erfolg', $this->translator->translate('admin.texte.zurueckgesetzt'));

            return Response::redirect($ziel);
        }

        $eingaben = $request->body['texte'] ?? [];

        if (!\is_array($eingaben)) {
            return Response::redirect($ziel);
        }

        $geaendert = 0;
        $fehler = [];

        foreach ($eingaben as $schluessel => $wert) {
            if (!\is_string($schluessel) || !\is_string($wert)) {
                continue;
            }

            $vorher = $this->texts->find($schluessel);

            // Unveraenderte Felder gar nicht erst anfassen: Sonst stuende nach
            // jedem Absenden die halbe Oberflaeche im Aenderungsprotokoll.
            if ($vorher === null || trim($wert) === $vorher->current) {
                continue;
            }

            try {
                $this->texts->update($schluessel, $wert, $admin->id);
                ++$geaendert;
            } catch (TextException $exception) {
                $fehler[] = $exception->getMessage();
            }
        }

        foreach ($fehler as $meldung) {
            $this->session->flash('fehler', $meldung);
        }

        if ($geaendert > 0) {
            $this->session->flash('erfolg', $this->translator->choose('admin.texte.gespeichert', $geaendert));
        } elseif ($fehler === []) {
            $this->session->flash('hinweis', $this->translator->translate('admin.texte.unveraendert'));
        }

        return Response::redirect($ziel);
    }

    /**
     * Suche und Bereich ueberleben das Speichern — sonst steht die Verwaltung
     * nach jeder Aenderung wieder am Anfang der Liste.
     */
    private function filterQuery(Request $request): string
    {
        $parameter = [];

        foreach (['q', 'bereich'] as $name) {
            $wert = $request->body[$name] ?? null;
            if (\is_string($wert) && $wert !== '') {
                $parameter[$name] = $wert;
            }
        }

        return $parameter === [] ? '' : '?' . http_build_query($parameter);
    }

    private function requireAdmin(): User
    {
        $user = $this->currentUser->require();

        // 404 statt 403 — die Verwaltung muss sich nicht dadurch verraten,
        // dass sie einen Zugriff ablehnt.
        if ($user->role !== Role::Admin) {
            throw HttpException::notFound('Seite nicht gefunden.');
        }

        return $user;
    }

    private function guardCsrf(Request $request): void
    {
        $this->session->assertCsrf($request);
    }
}
