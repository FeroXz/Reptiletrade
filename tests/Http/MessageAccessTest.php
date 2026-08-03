<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Message\MessagingService;
use Reptilienmarkt\Domain\Review\ReviewService;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Domain\Trust\TrustConfiguration;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Http\Controller\MessageController;
use Reptilienmarkt\Http\HttpException;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Session\NotAuthenticatedException;
use Reptilienmarkt\Http\Session\SessionManager;
use Reptilienmarkt\Http\View\TwigFactory;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoConversationRepository;
use Reptilienmarkt\Infra\Persistence\PdoListingRepository;
use Reptilienmarkt\Infra\Persistence\PdoMessageRepository;
use Reptilienmarkt\Infra\Persistence\PdoRateLimitRepository;
use Reptilienmarkt\Infra\Persistence\PdoReviewRepository;
use Reptilienmarkt\Infra\Persistence\PdoSessionRepository;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Infra\Persistence\PdoUserRepository;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;
use Reptilienmarkt\Tests\Support\StubViewer;

/**
 * Zugriffsschutz des Postfachs: Ein fremdes Gespraech ist nicht erreichbar,
 * und ohne Anmeldung geht gar nichts.
 */
#[CoversClass(MessageController::class)]
final class MessageAccessTest extends DatabaseTestCase
{
    private PdoConversationRepository $conversations;

    private MessagingService $messaging;

    private FrozenClock $clock;

    private int $sellerId;

    private int $buyerId;

    private int $conversationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-03T12:00:00+00:00'));

        $this->sellerId = $this->createUser('zuechter@example.tld');
        $this->buyerId = $this->createUser('kaeufer@example.tld');
        $listingId = $this->createListing($this->sellerId, $this->createSpecies());

        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 2) . '/config/trust.php';
        $trust = new TrustConfiguration($config);

        $this->conversations = new PdoConversationRepository($this->database);
        $this->messaging = new MessagingService(
            $this->conversations,
            new PdoMessageRepository($this->database),
            new PdoListingRepository($this->database),
            new RateLimiter(new PdoRateLimitRepository($this->database), $this->clock, $trust->rateLimits()),
            $trust->keywordFilter(),
            $trust->contactMasker(),
            new PdoAuditLog($this->database),
            $this->clock,
        );

        $conversation = $this->messaging->openConversation($listingId, $this->user($this->buyerId));
        $this->conversationId = $conversation->id ?? 0;
        $this->messaging->send($conversation, $this->user($this->buyerId), 'Ist das Tier noch da?');
    }

    private function user(int $id, Role $role = Role::Seller): User
    {
        return new User($id, 'nutzer' . $id . '@example.tld', 'Nutzer ' . $id, $role);
    }

    private function controller(?User $viewer): MessageController
    {
        $session = new SessionManager(new PdoSessionRepository($this->database), $this->clock);
        $session->start(new Request('GET', '/postfach/'));

        $translator = new Translator(\dirname(__DIR__, 2) . '/lang');

        return new MessageController(
            $this->conversations,
            $this->messaging,
            new ReviewService(new PdoReviewRepository($this->database), new PdoAuditLog($this->database), $this->clock),
            new PdoListingRepository($this->database),
            new PdoSpeciesRepository($this->database),
            new PdoUserRepository($this->database),
            new StubViewer($viewer),
            $session,
            $translator,
            TwigFactory::create(\dirname(__DIR__, 2) . '/templates', false, null, $translator),
        );
    }

    private function request(int $conversationId): Request
    {
        return (new Request('GET', '/postfach/' . $conversationId))
            ->withAttributes(['id' => (string) $conversationId]);
    }

    public function testOhneAnmeldungKeinPostfach(): void
    {
        $this->expectException(NotAuthenticatedException::class);

        $this->controller(null)->show($this->request($this->conversationId));
    }

    public function testDerKaeuferSiehtSeinGespraech(): void
    {
        $antwort = $this->controller($this->user($this->buyerId))->show($this->request($this->conversationId));

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('Ist das Tier noch da?', $antwort->body);
    }

    public function testDerVerkaeuferSiehtDasGespraechEbenfalls(): void
    {
        $antwort = $this->controller($this->user($this->sellerId))->show($this->request($this->conversationId));

        self::assertSame(200, $antwort->status);
    }

    /**
     * 404 statt 403: Ein 403 wuerde bestaetigen, dass es das Gespraech gibt.
     */
    public function testEinFremdesGespraechMeldetSichAlsNichtGefunden(): void
    {
        $fremder = $this->user($this->createUser('fremd@example.tld'));

        try {
            $this->controller($fremder)->show($this->request($this->conversationId));
            self::fail('Ein fremdes Gespräch darf nicht ausgeliefert werden.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }
    }

    public function testEinNichtVorhandenesGespraechMeldetDasselbe(): void
    {
        try {
            $this->controller($this->user($this->buyerId))->show($this->request(999999));
            self::fail('Nicht vorhandene Gespräche gibt es nicht.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
        }
    }

    public function testSendenOhneCsrfTokenWirdAbgelehnt(): void
    {
        $request = (new Request('POST', '/postfach/' . $this->conversationId . '/senden', [], ['nachricht' => 'Hallo']))
            ->withAttributes(['id' => (string) $this->conversationId]);

        try {
            $this->controller($this->user($this->buyerId))->send($request);
            self::fail('Ohne CSRF-Token darf nichts gesendet werden.');
        } catch (HttpException $exception) {
            self::assertSame(400, $exception->status);
        }
    }

    public function testDasPostfachZeigtNurEigeneGespraeche(): void
    {
        $fremder = $this->createUser('fremd@example.tld');

        $antwort = $this->controller($this->user($fremder))->inbox(new Request('GET', '/postfach/'));

        self::assertSame(200, $antwort->status);
        self::assertStringContainsString('Noch keine Gespräche', $antwort->body);
    }
}
