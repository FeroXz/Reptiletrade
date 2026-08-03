<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Admin\CatalogException;
use Reptilienmarkt\Domain\Admin\DashboardService;
use Reptilienmarkt\Domain\Admin\SpeciesCatalogService;
use Reptilienmarkt\Domain\Job\JobRepository;
use Reptilienmarkt\Domain\Privacy\RetentionPolicy;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Legal\Disclaimer;
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
        private RetentionPolicy $retention,
        private Viewer $currentUser,
        private SessionManager $session,
        private Environment $twig,
    ) {}

    public function dashboard(Request $request): Response
    {
        $this->requireAdmin();

        return Response::html($this->twig->render('admin/dashboard.html.twig', [
            'kennzahlen' => $this->dashboard->stats(),
            'fehlgeschlagene_jobs' => $this->jobs->recentFailures(10),
            'fristen' => $this->retention->all(),
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
