<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Search\AlertFrequency;
use Reptilienmarkt\Domain\Search\SavedSearch;
use Reptilienmarkt\Domain\Search\SavedSearchException;
use Reptilienmarkt\Domain\Search\SavedSearchService;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Search\SearchUrlBuilder;
use Reptilienmarkt\Http\Search\SearchUrlContext;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Support\Translator;
use Twig\Environment;

/**
 * Die gemerkten Suchen eines Kontos.
 */
final readonly class SavedSearchController
{
    public function __construct(
        private SavedSearchService $searches,
        private SpeciesRepository $species,
        private Viewer $currentUser,
        private SessionManager $session,
        private Translator $translator,
        private Environment $twig,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->currentUser->require();
        $suchen = $this->searches->forUser($user->id ?? 0);

        return Response::html($this->twig->render('konto/suchen.html.twig', [
            'suchen' => $suchen,
            // Die Adresse wird beim Anzeigen neu gebaut, nicht mitgespeichert —
            // sonst braeche jede gemerkte Suche beim naechsten Schemawechsel.
            'urls' => $this->urls($suchen),
            'grenze' => $this->searches->limit(),
            'frequenzen' => AlertFrequency::cases(),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    public function delete(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->session->assertCsrf($request);

        try {
            $this->searches->delete($user->id ?? 0, $this->requireId($request));
            $this->session->flash('erfolg', $this->translator->translate('suchen.geloescht'));
        } catch (SavedSearchException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect('/konto/suchen');
    }

    public function setAlert(Request $request): Response
    {
        $user = $this->currentUser->require();
        $this->session->assertCsrf($request);

        $wert = $request->body['frequenz'] ?? '';
        $frequenz = AlertFrequency::tryFrom(\is_string($wert) ? $wert : '');

        if ($frequenz === null) {
            $this->session->flash('fehler', $this->translator->translate('suchen.frequenz_unbekannt'));

            return Response::redirect('/konto/suchen');
        }

        try {
            $this->searches->setAlertFrequency($user->id ?? 0, $this->requireId($request), $frequenz);
            $this->session->flash('erfolg', $this->translator->translate('suchen.gespeichert'));
        } catch (SavedSearchException $exception) {
            $this->session->flash('fehler', $exception->getMessage());
        }

        return Response::redirect('/konto/suchen');
    }

    /**
     * Zu jeder Suche die Adresse, unter der sie wieder laeuft.
     *
     * Der URL-Bauer braucht fuer den SEO-Pfad die aufgeloeste Art. Fehlt sie —
     * etwa weil die Art inzwischen weg ist — entsteht eben eine Adresse mit
     * Query-Parametern statt eines Pfades. Dieselbe Suche, andere Schreibweise.
     *
     * @param list<SavedSearch> $searches
     *
     * @return array<int, string>
     */
    private function urls(array $searches): array
    {
        $adressen = [];

        foreach ($searches as $suche) {
            $art = $suche->criteria->speciesId === null
                ? null
                : $this->species->findById($suche->criteria->speciesId);

            $adressen[$suche->id ?? 0] = SearchUrlBuilder::build(
                $suche->criteria,
                new SearchUrlContext($art),
            );
        }

        return $adressen;
    }

    private function requireId(Request $request): int
    {
        $value = $request->attribute('id');

        if ($value === null || !ctype_digit($value)) {
            throw HttpException::notFound('Nicht gefunden.');
        }

        return (int) $value;
    }
}
