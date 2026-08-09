<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Site\SiteIdentityException;
use Reptilienmarkt\Domain\Site\SiteIdentityService;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Legal\LegalPageException;
use Reptilienmarkt\Legal\LegalPageService;
use Reptilienmarkt\Legal\LegalText;
use Reptilienmarkt\Support\Translator;
use Twig\Environment;

/**
 * Impressum, Datenschutzerklaerung und Nutzungsbedingungen pflegen.
 *
 * Zugang **nur fuer die Rolle admin** — nicht fuer die Redaktion. Wer diese
 * Seiten aendert, aendert, wofuer der Betreiber haftet; das ist etwas anderes
 * als einen Beitrag zu schreiben. Dieselbe Linie wie bei /admin/texte.
 *
 * Zwei Haelften, weil es zwei verschiedene Dinge sind: Die Stammdaten sind
 * Felder mit Pflichtangaben (§ 5 DDG), die Abschnitte sind Fliesstext mit
 * Fundstelle und Pruefdatum.
 */
final readonly class AdminLegalController
{
    public function __construct(
        private SiteIdentityService $identity,
        private LegalPageService $pages,
        private Viewer $currentUser,
        private SessionManager $session,
        private Translator $translator,
        private Environment $twig,
    ) {}

    /**
     * GET /admin/recht
     */
    public function index(Request $request): Response
    {
        $this->requireAdmin();

        return Response::html($this->twig->render('admin/recht.html.twig', [
            'felder' => $this->identity->fields(),
            'schalter' => $this->identity->flags(),
            'geaendert' => $this->identity->changedCount(),
            'fehlend' => $this->identity->identity()->missing(),
            'vollstaendig' => $this->identity->identity()->isComplete(),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    /**
     * POST /admin/recht
     */
    public function save(Request $request): Response
    {
        $admin = $this->requireAdmin();
        $this->session->assertCsrf($request);

        // Ein Zuruecksetzen-Knopf traegt seinen Schluessel im Wert. So kommt
        // die ganze Seite mit einer Absendung aus — auch ohne Javascript.
        $zuruecksetzen = $this->text($request, 'zuruecksetzen');

        if ($zuruecksetzen !== '') {
            try {
                $this->identity->reset($zuruecksetzen, $admin->id ?? 0);
                $this->session->flash('erfolg', $this->translator->translate('admin.recht.zurueckgesetzt'));
            } catch (SiteIdentityException $exception) {
                $this->session->flash('fehler', $exception->getMessage());
            }

            return Response::redirect('/admin/recht');
        }

        /** @var array<string, string> $werte */
        $werte = [];
        $roh = $request->body['feld'] ?? null;

        if (\is_array($roh)) {
            foreach ($roh as $key => $value) {
                if (\is_string($key) && \is_string($value)) {
                    $werte[$key] = $value;
                }
            }
        }

        // Nicht angehakte Kontrollkaestchen schickt der Browser gar nicht mit.
        // Deshalb steht die Liste der Schalter hier und nicht im Formular —
        // sonst bliebe ein abgewaehlter Schalter fuer immer gesetzt.
        $schalter = [];
        $gesetzt = $request->body['schalter'] ?? null;

        foreach (array_keys($this->identity->flags()) as $key) {
            $schalter[$key] = \is_array($gesetzt) && ($gesetzt[$key] ?? '') !== '';
        }

        try {
            $anzahl = $this->identity->save($werte, $schalter, $admin->id ?? 0);
        } catch (SiteIdentityException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect('/admin/recht');
        }

        $this->session->flash('erfolg', $anzahl === 0
            ? $this->translator->translate('admin.recht.unveraendert')
            : $this->translator->translate('admin.recht.gespeichert', ['anzahl' => $anzahl]));

        return Response::redirect('/admin/recht');
    }

    /**
     * GET /admin/recht/abschnitte
     */
    public function sections(Request $request): Response
    {
        $this->requireAdmin();

        $seite = $request->queryString('seite', 'impressum') ?? 'impressum';

        if (!isset(LegalPageService::PAGES[$seite])) {
            $seite = 'impressum';
        }

        $abschnitte = $this->pages->sections($seite);
        $geaendert = [];

        foreach ($abschnitte as $abschnitt) {
            $geaendert[$abschnitt->key] = $this->pages->isChanged($abschnitt);
        }

        return Response::html($this->twig->render('admin/recht_abschnitte.html.twig', [
            'seiten' => LegalPageService::PAGES,
            'seite' => $seite,
            'abschnitte' => $abschnitte,
            'geaendert' => $geaendert,
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    /**
     * POST /admin/recht/abschnitte
     */
    public function saveSection(Request $request): Response
    {
        $admin = $this->requireAdmin();
        $this->session->assertCsrf($request);

        $seite = $this->text($request, 'seite');
        $ziel = '/admin/recht/abschnitte?seite=' . rawurlencode($seite);
        $aktion = $this->text($request, 'aktion');
        $key = $this->text($request, 'schluessel');

        try {
            match ($aktion) {
                'zuruecksetzen' => $this->pages->reset($key, $admin->id ?? 0),
                'geprueft' => $this->pages->markReviewed($key, $admin->id ?? 0),
                default => $this->pages->save(
                    $key,
                    $this->text($request, 'titel'),
                    $this->text($request, 'text'),
                    $this->text($request, 'fundstelle'),
                    $admin->id ?? 0,
                ),
            };
        } catch (LegalPageException $exception) {
            $this->session->flash('fehler', $exception->getMessage());

            return Response::redirect($ziel);
        }

        $this->session->flash('erfolg', $this->translator->translate(match ($aktion) {
            'zuruecksetzen' => 'admin.recht.abschnitt_zurueckgesetzt',
            'geprueft' => 'admin.recht.abschnitt_geprueft',
            default => 'admin.recht.abschnitt_gespeichert',
        }));

        return Response::redirect($ziel);
    }

    /**
     * Wie alt ist die letzte Pruefung? Fuer die Anzeige in der Liste.
     */
    public function isStale(LegalText $text): bool
    {
        return $text->lastReviewedAt === null;
    }

    private function text(Request $request, string $key): string
    {
        $value = $request->body[$key] ?? null;

        return \is_string($value) ? trim($value) : '';
    }

    private function requireAdmin(): User
    {
        $user = $this->currentUser->require();

        // 404 statt 403 — dieselbe Linie wie im uebrigen Verwaltungsbereich.
        if ($user->role !== Role::Admin) {
            throw HttpException::notFound('Seite nicht gefunden.');
        }

        return $user;
    }
}
