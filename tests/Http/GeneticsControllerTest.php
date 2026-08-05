<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Genetics\CrossSimulation;
use Reptilienmarkt\Domain\Genetics\GeneticsConfiguration;
use Reptilienmarkt\Domain\Genetics\GeneticsSimulationRepository;
use Reptilienmarkt\Domain\Listing\Zygosity;
use Reptilienmarkt\Domain\Species\Inheritance;
use Reptilienmarkt\Domain\Species\Morph;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Domain\Trust\TrustConfiguration;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\Controller\GeneticsController;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Session\NotAuthenticatedException;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\View\TwigFactory;
use Reptilienmarkt\Infra\Genetics\PdfReportGenerator;
use Reptilienmarkt\Infra\Persistence\PdoGeneticsSimulationRepository;
use Reptilienmarkt\Infra\Persistence\PdoListingRepository;
use Reptilienmarkt\Infra\Persistence\PdoMorphRepository;
use Reptilienmarkt\Infra\Persistence\PdoRateLimitRepository;
use Reptilienmarkt\Infra\Persistence\PdoSessionRepository;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Support\Log\NullLogger;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;
use Reptilienmarkt\Tests\Support\StubViewer;

/**
 * Der Rechner ueber HTTP: Zugriffsschutz, JSON-Antwort, PDF-Auslieferung.
 */
#[CoversClass(GeneticsController::class)]
#[CoversClass(PdfReportGenerator::class)]
#[CoversClass(PdoGeneticsSimulationRepository::class)]
final class GeneticsControllerTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private GeneticsSimulationRepository $simulations;

    private int $userId;

    private int $speciesId;

    private int $hypoId;

    private int $leatherbackId;

    private string $csrf = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-05T12:00:00+00:00'));
        $this->userId = $this->createUser('zuechter@example.tld');
        $this->simulations = new PdoGeneticsSimulationRepository($this->database);

        $species = new PdoSpeciesRepository($this->database);
        $this->speciesId = $species->save(new Species(null, 'Pogona vitticeps', 'Bartagame', 'pogona-vitticeps'));

        $morphs = new PdoMorphRepository($this->database);
        $this->hypoId = $morphs->save(new Morph(null, $this->speciesId, 'Hypomelanistic', Inheritance::Recessive, ['Hypo']));
        $this->leatherbackId = $morphs->save(
            new Morph(null, $this->speciesId, 'Leatherback', Inheritance::IncompleteDominant, [], 'leatherback'),
        );
        $morphs->save(new Morph(null, $this->speciesId, 'Silkback', Inheritance::IncompleteDominant, [], 'leatherback'));
    }

    private function user(?int $id = null): User
    {
        return new User($id ?? $this->userId, 'zuechter@example.tld', 'Züchter', Role::Seller);
    }

    private function controller(?User $viewer, bool $enabled = true, int $rollout = 100): GeneticsController
    {
        $session = new SessionManager(new PdoSessionRepository($this->database), $this->clock);
        $session->start(new Request('GET', '/paarung/simulator'));
        $this->csrf = $session->csrfToken();

        /** @var array<string, mixed> $geneticsConfig */
        $geneticsConfig = require \dirname(__DIR__, 2) . '/config/genetik.php';
        $geneticsConfig['enabled'] = $enabled;
        $geneticsConfig['rollout_percentage'] = $rollout;

        /** @var array<string, mixed> $trustConfig */
        $trustConfig = require \dirname(__DIR__, 2) . '/config/trust.php';

        $translator = new Translator(\dirname(__DIR__, 2) . '/lang');
        $twig = TwigFactory::create(\dirname(__DIR__, 2) . '/templates', false, null, $translator);
        $config = new GeneticsConfiguration($geneticsConfig);
        $morphs = new PdoMorphRepository($this->database);
        $species = new PdoSpeciesRepository($this->database);

        return new GeneticsController(
            new CrossSimulation($morphs, $species, $config, $this->clock),
            $this->simulations,
            new PdfReportGenerator($twig),
            $config,
            new PdoListingRepository($this->database),
            $species,
            $morphs,
            new RateLimiter(
                new PdoRateLimitRepository($this->database),
                $this->clock,
                (new TrustConfiguration($trustConfig))->rateLimits(),
            ),
            new StubViewer($viewer),
            $session,
            $twig,
            new NullLogger(),
            $this->clock,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body, bool $json = true): Request
    {
        return new Request(
            'POST',
            '/paarung/simulator',
            [],
            $body + ['_csrf' => $this->csrf],
            $json ? ['accept' => 'application/json'] : [],
        );
    }

    // ------------------------------------------------------ Zugriffsschutz

    public function testOhneAnmeldungKeinRechner(): void
    {
        $this->expectException(NotAuthenticatedException::class);

        $this->controller(null)->form(new Request('GET', '/paarung/simulator'));
    }

    /**
     * Ist das Merkmal abgeschaltet, gibt es die Seite nicht — kein 403, das
     * waere eine Auskunft ueber etwas, das es fuer dieses Konto nicht gibt.
     */
    public function testAbgeschaltetesMerkmalIstNichtErreichbar(): void
    {
        try {
            $this->controller($this->user(), enabled: false)->form(new Request('GET', '/paarung/simulator'));
            self::fail('Bei abgeschaltetem Merkmal darf es die Seite nicht geben.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }
    }

    public function testStufenweiseFreischaltungSchliesstKontenAus(): void
    {
        try {
            $this->controller($this->user(), rollout: 0)->form(new Request('GET', '/paarung/simulator'));
            self::fail('Bei 0 % Freischaltung darf niemand hinein.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }
    }

    // ------------------------------------------------------------ Formular

    public function testDasFormularZeigtDieMerkmaleDerGewaehltenArt(): void
    {
        $request = new Request('GET', '/paarung/simulator', ['art_id' => (string) $this->speciesId]);

        $response = $this->controller($this->user())->form($request);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Hypomelanistic', $response->body);
        self::assertStringContainsString('Leatherback', $response->body);
    }

    // ------------------------------------------------------------ Rechnung

    public function testSimulationLiefertJson(): void
    {
        $response = $this->controller($this->user())->simulate($this->post([
            'art_id' => (string) $this->speciesId,
            'a_geschlecht' => 'm',
            'a_morph' => [(string) $this->hypoId => 'het'],
            'b_geschlecht' => 'w',
            'b_morph' => [(string) $this->hypoId => 'het'],
        ]));

        self::assertSame(200, $response->status);

        /** @var array<string, mixed> $data */
        $data = json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('id', $data);
        self::assertArrayHasKey('phaenotypen', $data);
        self::assertArrayHasKey('warnungen', $data);
        self::assertArrayHasKey('erwartete_schluepflinge', $data);

        /** @var array<string, float> $phaenotypen */
        $phaenotypen = $data['phaenotypen'];
        self::assertEqualsWithDelta(0.25, $phaenotypen['Hypo'], 0.0001);
        self::assertSame('/paarung/simulator/' . $data['id'] . '/pdf', $data['pdf']);
    }

    public function testJedeRechnungWirdAlsBerichtGespeichert(): void
    {
        $this->controller($this->user())->simulate($this->post([
            'art_id' => (string) $this->speciesId,
            'a_morph' => [(string) $this->leatherbackId => 'visual'],
            'b_morph' => [(string) $this->leatherbackId => 'visual'],
        ]));

        $berichte = $this->simulations->forUser($this->userId);

        self::assertCount(1, $berichte);
        self::assertSame($this->speciesId, $berichte[0]->speciesId);
        self::assertArrayHasKey('Silkback', $berichte[0]->result->offspringPhenotypes());
    }

    public function testOhneCsrfTokenWirdNichtGerechnet(): void
    {
        $controller = $this->controller($this->user());
        $request = new Request('POST', '/paarung/simulator', [], ['art_id' => (string) $this->speciesId]);

        try {
            $controller->simulate($request);
            self::fail('Ohne CSRF-Token darf nicht gerechnet werden.');
        } catch (HttpException $exception) {
            self::assertSame(400, $exception->status);
        }
    }

    public function testVerschiedeneArtenErgebenEineVerstaendlicheFehlermeldung(): void
    {
        $andere = (new PdoSpeciesRepository($this->database))->save(
            new Species(null, 'Eublepharis macularius', 'Leopardgecko', 'eublepharis-macularius'),
        );

        $listingA = $this->createListing($this->userId, $this->speciesId);
        $listingB = $this->createListing($this->userId, $andere);

        $response = $this->controller($this->user())->simulate($this->post([
            'a_anzeige_id' => (string) $listingA,
            'b_anzeige_id' => (string) $listingB,
        ]));

        self::assertSame(400, $response->status);

        /** @var array<string, string> $data */
        $data = json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('verschiedenen Arten', $data['fehler']);
    }

    public function testUnbekannteAnzeigeMeldetSichAlsNichtGefunden(): void
    {
        $response = $this->controller($this->user())->simulate($this->post([
            'a_anzeige_id' => '999999',
            'b_anzeige_id' => '999998',
        ]));

        self::assertSame(404, $response->status);
    }

    public function testElterntiereKoennenAusAnzeigenKommen(): void
    {
        $listingId = $this->createListing($this->userId, $this->speciesId);
        $listings = new PdoListingRepository($this->database);
        $listings->replaceMorphs($listingId, [$this->hypoId => Zygosity::Visual]);

        $response = $this->controller($this->user())->simulate($this->post([
            'art_id' => (string) $this->speciesId,
            'a_anzeige_id' => (string) $listingId,
            'b_geschlecht' => 'w',
            'b_morph' => [(string) $this->hypoId => 'het'],
        ]));

        self::assertSame(200, $response->status);

        /** @var array<string, mixed> $data */
        $data = json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
        /** @var array<string, float> $phaenotypen */
        $phaenotypen = $data['phaenotypen'];

        self::assertEqualsWithDelta(0.5, $phaenotypen['Hypo'], 0.0001);
        self::assertEqualsWithDelta(0.5, $phaenotypen['het Hypo'], 0.0001);
    }

    // ----------------------------------------------------------------- PDF

    public function testDerBerichtLaesstSichAlsPdfHerunterladen(): void
    {
        $controller = $this->controller($this->user());
        $controller->simulate($this->post([
            'art_id' => (string) $this->speciesId,
            'a_morph' => [(string) $this->hypoId => 'het'],
            'b_morph' => [(string) $this->hypoId => 'het'],
        ]));

        $id = $this->simulations->forUser($this->userId)[0]->id ?? 0;

        $response = $controller->downloadPdf(
            (new Request('GET', '/paarung/simulator/' . $id . '/pdf'))->withAttributes(['id' => (string) $id]),
        );

        self::assertSame(200, $response->status);
        self::assertSame('application/pdf', $response->headers['content-type']);
        self::assertStringContainsString('genetik-bericht-' . $id, $response->headers['content-disposition']);
        self::assertStringStartsWith('%PDF-1.4', $response->body);
        self::assertStringContainsString('%%EOF', $response->body);
    }

    /**
     * 404 statt 403 — ein 403 wuerde bestaetigen, dass es den Bericht gibt.
     */
    public function testEinFremderBerichtMeldetSichAlsNichtGefunden(): void
    {
        $controller = $this->controller($this->user());
        $controller->simulate($this->post([
            'art_id' => (string) $this->speciesId,
            'a_morph' => [(string) $this->hypoId => 'het'],
            'b_morph' => [(string) $this->hypoId => 'het'],
        ]));

        $id = $this->simulations->forUser($this->userId)[0]->id ?? 0;
        $fremder = $this->user($this->createUser('fremd@example.tld'));

        try {
            $this->controller($fremder)->downloadPdf(
                (new Request('GET', '/paarung/simulator/' . $id . '/pdf'))->withAttributes(['id' => (string) $id]),
            );
            self::fail('Ein fremder Bericht darf nicht ausgeliefert werden.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }
    }

    // ------------------------------------------------------------ Berichte

    public function testDieBerichtsliteZeigtNurEigeneBerichte(): void
    {
        $controller = $this->controller($this->user());
        $controller->simulate($this->post([
            'art_id' => (string) $this->speciesId,
            'a_morph' => [(string) $this->hypoId => 'visual'],
            'b_morph' => [(string) $this->hypoId => 'het'],
        ]));

        $response = $controller->myReports(new Request('GET', '/konto/genetik-berichte'));
        self::assertSame(200, $response->status);
        self::assertStringContainsString('Hypo', $response->body);

        $fremder = $this->user($this->createUser('fremd@example.tld'));
        $leer = $this->controller($fremder)->myReports(new Request('GET', '/konto/genetik-berichte'));

        self::assertStringContainsString('Noch keine Berichte', $leer->body);
    }

    public function testEinBerichtLaesstSichLoeschen(): void
    {
        $controller = $this->controller($this->user());
        $controller->simulate($this->post([
            'art_id' => (string) $this->speciesId,
            'a_morph' => [(string) $this->hypoId => 'het'],
            'b_morph' => [(string) $this->hypoId => 'het'],
        ]));

        $id = $this->simulations->forUser($this->userId)[0]->id ?? 0;

        $request = (new Request('POST', '/konto/genetik-berichte/' . $id . '/loeschen', [], ['_csrf' => $this->csrf]))
            ->withAttributes(['id' => (string) $id]);

        $response = $controller->deleteReport($request);

        self::assertSame(302, $response->status);
        self::assertSame([], $this->simulations->forUser($this->userId));
    }
}
