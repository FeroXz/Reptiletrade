<?php

declare(strict_types=1);

namespace Reptilienmarkt\Http\Controller;

use Reptilienmarkt\Domain\Genetics\BreedingAnimal;
use Reptilienmarkt\Domain\Genetics\CrossSimulation;
use Reptilienmarkt\Domain\Genetics\GeneticsConfiguration;
use Reptilienmarkt\Domain\Genetics\GeneticsException;
use Reptilienmarkt\Domain\Genetics\GeneticsSimulationRepository;
use Reptilienmarkt\Domain\Genetics\GeneticWarning;
use Reptilienmarkt\Domain\Genetics\IncompatibleSpeciesException;
use Reptilienmarkt\Domain\Genetics\LethalCrossException;
use Reptilienmarkt\Domain\Genetics\SimulationResult;
use Reptilienmarkt\Domain\Genetics\StoredSimulation;
use Reptilienmarkt\Domain\Listing\ListingRepository;
use Reptilienmarkt\Domain\Listing\MorphSelection;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Listing\Zygosity;
use Reptilienmarkt\Domain\Species\Morph;
use Reptilienmarkt\Domain\Species\MorphRepository;
use Reptilienmarkt\Domain\Species\SpeciesRepository;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Message\Response;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\Session\Viewer;
use Reptilienmarkt\Infra\Genetics\PdfReportGenerator;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Log\Logger;
use Twig\Environment;

/**
 * Der Verpaarungsrechner.
 *
 * Die Elterntiere kommen entweder aus einer Anzeige (dann stehen Art, Geschlecht
 * und Merkmale schon fest) oder werden von Hand zusammengestellt — ein Zuechter
 * rechnet auch mit Tieren, die er nicht anbietet.
 *
 * Jeder Lauf wird gespeichert. Das ist der Zweck der Sache: Der Bericht laesst
 * sich spaeter wieder aufrufen und als PDF beilegen, und die Zahlen darin
 * aendern sich nicht mehr, auch wenn der Merkmalskatalog es tut.
 */
final readonly class GeneticsController
{
    public function __construct(
        private CrossSimulation $simulation,
        private GeneticsSimulationRepository $simulations,
        private PdfReportGenerator $reports,
        private GeneticsConfiguration $config,
        private ListingRepository $listings,
        private SpeciesRepository $species,
        private MorphRepository $morphs,
        private RateLimiter $rateLimiter,
        private Viewer $currentUser,
        private SessionManager $session,
        private Environment $twig,
        private Logger $logger,
        private Clock $clock,
    ) {}

    /**
     * GET /paarung/simulator
     */
    public function form(Request $request): Response
    {
        $user = $this->requireAccess();

        return Response::html($this->twig->render('genetik/rechner.html.twig', $this->formData($user, $request)));
    }

    /**
     * POST /paarung/simulator
     */
    public function simulate(Request $request): Response
    {
        $user = $this->requireAccess();
        $payload = $this->payload($request);
        $this->guardCsrf($request, $payload);

        $decision = $this->rateLimiter->attempt('genetik.konto', (string) ($user->id ?? 0));
        if (!$decision->allowed) {
            return $this->fail($request, 'Zu viele Berechnungen in kurzer Zeit. Bitte versuche es später noch einmal.', 429);
        }

        try {
            $first = $this->animal($payload, 'a');
            $second = $this->animal($payload, 'b');
        } catch (HttpException $exception) {
            return $this->fail($request, $exception->getMessage(), $exception->status);
        }

        $start = microtime(true);

        try {
            $result = $this->simulation->cross($first, $second);
        } catch (IncompatibleSpeciesException | LethalCrossException $exception) {
            $this->logger->info('genetik.simulation_abgewiesen', [
                'nutzer' => $user->id,
                'grund' => $exception::class,
                'meldung' => $exception->getMessage(),
            ]);

            return $this->fail($request, $exception->getMessage(), 400);
        } catch (GeneticsException $exception) {
            $this->logger->error('genetik.simulation_fehlgeschlagen', [
                'nutzer' => $user->id,
                'meldung' => $exception->getMessage(),
            ]);

            return $this->fail($request, $exception->getMessage(), 400);
        }

        $durationMs = (microtime(true) - $start) * 1000;

        $stored = $this->simulations->save(new StoredSimulation(
            null,
            $user->id ?? 0,
            $first->speciesId,
            $this->title($result),
            $result,
            $first->listingId,
            $second->listingId,
            $this->clock->now(),
        ));

        // Die Genotypen stehen als Kurzform im Protokoll und nicht als Liste:
        // Der Logger schreibt aus Listen nur die Zeichenketten-Schluessel, und
        // "hypo/+ leather/+" ist ohnehin die Form, in der man es liest.
        $this->logger->info('genetik.simulation', [
            'nutzer' => $user->id,
            'bericht' => $stored,
            'art' => $first->speciesId,
            'eltern_a' => $result->parentage()[0]->genotypeString,
            'eltern_b' => $result->parentage()[1]->genotypeString,
            'phaenotypen' => \count($result->offspringPhenotypes()),
            'warnungen' => \count($result->warnings()),
            'letal_anteil' => round($result->lethalShare(), 4),
            'dauer_ms' => round($durationMs, 1),
        ]);

        if ($request->wantsJson()) {
            return Response::json($this->json($stored, $result));
        }

        $data = $this->formData($user, $request);
        $data['ergebnis'] = $result;
        $data['bericht_id'] = $stored;

        return Response::html($this->twig->render('genetik/rechner.html.twig', $data));
    }

    /**
     * GET /paarung/simulator/{id}/pdf
     */
    public function downloadPdf(Request $request): Response
    {
        $user = $this->requireAccess();
        $simulation = $this->requireOwnReport($request, $user);

        return new Response(
            $this->reports->generatePdf($simulation->result),
            200,
            [
                'content-type' => 'application/pdf',
                'content-disposition' => \sprintf('attachment; filename="genetik-bericht-%d.pdf"', $simulation->id ?? 0),
                // Der Bericht enthaelt Angaben zum eigenen Zuchtbestand.
                'cache-control' => 'private, no-store',
            ],
        );
    }

    /**
     * GET /konto/genetik-berichte
     */
    public function myReports(Request $request): Response
    {
        $user = $this->requireAccess();

        return Response::html($this->twig->render('genetik/berichte.html.twig', [
            'berichte' => $this->simulations->forUser($user->id ?? 0),
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ]));
    }

    /**
     * POST /konto/genetik-berichte/{id}/loeschen
     */
    public function deleteReport(Request $request): Response
    {
        $user = $this->requireAccess();
        $this->session->assertCsrf($request);
        $simulation = $this->requireOwnReport($request, $user);

        $this->simulations->deleteById($simulation->id ?? 0);
        $this->session->flash('erfolg', 'Der Bericht ist gelöscht.');

        return Response::redirect('/konto/genetik-berichte');
    }

    /**
     * Ohne Anmeldung geht nichts, und bei abgeschaltetem Merkmal gibt es die
     * Seite nicht — kein 403, das waere eine Auskunft ueber etwas, das es
     * fuer dieses Konto nicht gibt.
     */
    private function requireAccess(): User
    {
        $user = $this->currentUser->require();

        if (!$this->config->isEnabledFor($user->id)) {
            throw HttpException::notFound('Nicht gefunden.');
        }

        return $user;
    }

    private function requireOwnReport(Request $request, User $user): StoredSimulation
    {
        $id = $request->attribute('id');

        if ($id === null || !ctype_digit($id)) {
            throw HttpException::notFound('Nicht gefunden.');
        }

        $simulation = $this->simulations->findById((int) $id);

        // Ein fremder Bericht antwortet mit 404, nicht 403: Ein 403 wuerde
        // bestaetigen, dass es ihn gibt.
        if ($simulation === null || !$simulation->belongsTo($user->id ?? 0)) {
            throw HttpException::notFound('Nicht gefunden.');
        }

        return $simulation;
    }

    /**
     * Baut ein Elterntier aus der Anfrage: entweder aus einer Anzeige oder aus
     * den von Hand gewaehlten Merkmalen.
     *
     * @param array<string, mixed> $payload
     */
    private function animal(array $payload, string $prefix): BreedingAnimal
    {
        $listingId = $this->intOrNull($payload, $prefix . '_anzeige_id');

        if ($listingId !== null) {
            $listing = $this->listings->findById($listingId);

            if ($listing === null || $listing->id === null) {
                throw HttpException::notFound(\sprintf('Die Anzeige %d gibt es nicht.', $listingId));
            }

            $species = $this->species->findById($listing->speciesId);

            return BreedingAnimal::fromListing(
                $listing->id,
                $listing->speciesId,
                $listing->sex,
                $this->listings->morphSelections($listing->id),
                \sprintf(
                    '%s – %s',
                    $species?->commonNameDe ?? 'Anzeige',
                    $listing->title !== '' ? $listing->title : 'Anzeige #' . $listing->id,
                ),
            );
        }

        $speciesId = $this->intOrNull($payload, 'art_id')
            ?? throw HttpException::badRequest('Bitte gib die Art an.');

        if ($this->species->findById($speciesId) === null) {
            throw HttpException::notFound('Diese Art gibt es nicht.');
        }

        return new BreedingAnimal(
            $speciesId,
            Sex::tryFrom($this->stringValue($payload, $prefix . '_geschlecht')) ?? Sex::Unbekannt,
            $this->selections($payload, $prefix, $speciesId),
        );
    }

    /**
     * Merkmale in der Form a_morph[ID] = auspraegung — dieselbe Schreibweise
     * wie im Anzeigenassistenten.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<MorphSelection>
     */
    private function selections(array $payload, string $prefix, int $speciesId): array
    {
        $raw = $payload[$prefix . '_morph'] ?? [];

        if (!\is_array($raw)) {
            return [];
        }

        $catalog = [];
        foreach ($this->morphs->forSpecies($speciesId) as $morph) {
            if ($morph->id !== null) {
                $catalog[$morph->id] = $morph;
            }
        }

        $selections = [];
        foreach ($raw as $morphId => $zygosity) {
            if (!is_numeric($morphId) || !\is_string($zygosity) || $zygosity === '') {
                continue;
            }

            $morph = $catalog[(int) $morphId] ?? null;

            // Ein Merkmal einer anderen Art wird stillschweigend uebergangen —
            // es kann nur aus einer manipulierten Anfrage stammen.
            if (!$morph instanceof Morph) {
                continue;
            }

            $selections[] = new MorphSelection($morph, Zygosity::tryFrom($zygosity) ?? Zygosity::Visual);
        }

        return $selections;
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(User $user, Request $request): array
    {
        // Die Art steht beim Aufruf der Seite im Query-String (Schritt 1 des
        // Formulars) und beim Rechnen im Rumpf.
        $speciesId = $this->intOrNull($request->query, 'art_id')
            ?? $this->intOrNull($this->payload($request), 'art_id');
        $species = $speciesId === null ? null : $this->species->findById($speciesId);

        return [
            'arten' => $this->species->all(),
            'gewaehlte_art' => $species,
            'morphs' => $species?->id === null ? [] : $this->morphs->forSpecies($species->id),
            'zygositaeten' => Zygosity::cases(),
            'geschlechter' => Sex::cases(),
            'meine_anzeigen' => $this->listings->forUser($user->id ?? 0),
            'ergebnis' => null,
            'bericht_id' => null,
            'csrf' => $this->session->csrfToken(),
            'meldungen' => $this->session->takeFlashes(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(int $id, SimulationResult $result): array
    {
        return [
            'id' => $id,
            'art' => $result->speciesName(),
            'eltern' => [$result->parentage()[0]->toArray(), $result->parentage()[1]->toArray()],
            'phaenotypen' => $result->offspringPhenotypes(),
            'phaenotypen_nach_geschlecht' => $result->offspringPhenotypesBySex(),
            'genotypen' => $result->offspringGenotypes(),
            'warnungen' => array_map(
                static fn(GeneticWarning $warning): array => $warning->toArray(),
                $result->warnings(),
            ),
            'letal_anteil' => round($result->lethalShare(), 4),
            'gelege' => $result->expectedClutchSize(),
            'erwartete_schluepflinge' => $result->expectedOffspringCount(),
            'pdf' => \sprintf('/paarung/simulator/%d/pdf', $id),
        ];
    }

    private function title(SimulationResult $result): string
    {
        [$first, $second] = $result->parentage();

        return mb_substr(\sprintf('%s × %s', $first->morphString, $second->morphString), 0, 200);
    }

    /**
     * Formularfelder und JSON-Rumpf ueber denselben Weg. Die REST-Schnittstelle
     * ist dieselbe Handlung, nur ohne Formular.
     *
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        if ($request->body !== []) {
            return $request->body;
        }

        $raw = $request->raw();

        if ($raw === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function guardCsrf(Request $request, array $payload): void
    {
        $token = $payload['_csrf'] ?? null;

        if (\is_string($token) && $this->session->verifyCsrf($token)) {
            return;
        }

        // Faellt auf die ausfuehrliche Pruefung zurueck: Sie unterscheidet
        // "Formular abgelaufen" von "gar keine Sitzung" und protokolliert es.
        $this->session->assertCsrf($request);
    }

    private function fail(Request $request, string $message, int $status): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['fehler' => $message], $status);
        }

        $this->session->flash('fehler', $message);

        return Response::redirect('/paarung/simulator');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function intOrNull(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        if (\is_int($value)) {
            return $value;
        }

        return \is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringValue(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return \is_string($value) ? trim($value) : '';
    }
}
