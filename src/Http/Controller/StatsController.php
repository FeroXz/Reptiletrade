<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Billing\EntitlementService;
use Reptilienmarkt\Domain\Billing\Feature;
use Reptilienmarkt\Domain\Listing\SellerStatsService;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Twig\Environment;

/**
 * Aufrufe und Anfragen der eigenen Anzeigen.
 *
 * Die Zahlen sieht ausschliesslich der Anbieter selbst. Sie oeffentlich zu
 * zeigen waere eine Einladung: Wer sieht, dass eine Anzeige kaum aufgerufen
 * wird, handelt anders — und wer die Zahlen fremder Anbieter kennt, kann sein
 * Angebot danach ausrichten.
 */
final readonly class StatsController
{
    public function __construct(
        private SellerStatsService $stats,
        private EntitlementService $entitlements,
        private Viewer $currentUser,
        private SessionManager $session,
        private Environment $twig,
    ) {}

    public function show(Request $request): Response
    {
        $user = $this->currentUser->require();
        $tage = $request->queryInt('tage', SellerStatsService::DEFAULT_DAYS) ?? SellerStatsService::DEFAULT_DAYS;

        return Response::html($this->twig->render('konto/statistik.html.twig', [
            'statistik' => $this->stats->forUser($user->id ?? 0, $tage),
            // Zurzeit fuer alle offen. Steht das Merkmal spaeter wieder im
            // Tarif, greift diese Pruefung ohne weitere Aenderung.
            'im_tarif' => $this->entitlements->forUser($user)->has(Feature::Statistiken),
            'zeitraeume' => [7, 30, 90],
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }
}
