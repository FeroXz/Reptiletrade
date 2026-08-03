<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Message;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Message\Conversation;
use Reptilienmarkt\Domain\Message\MessagingException;
use Reptilienmarkt\Domain\Message\MessagingService;
use Reptilienmarkt\Domain\Trust\RateLimit;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Domain\Trust\RateLimitExceededException;
use Reptilienmarkt\Domain\Trust\TrustConfiguration;
use Reptilienmarkt\Domain\User\Role;
use Reptilienmarkt\Domain\User\User;
use Reptilienmarkt\Infra\Persistence\PdoAuditLog;
use Reptilienmarkt\Infra\Persistence\PdoConversationRepository;
use Reptilienmarkt\Infra\Persistence\PdoListingRepository;
use Reptilienmarkt\Infra\Persistence\PdoMessageRepository;
use Reptilienmarkt\Infra\Persistence\PdoRateLimitRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(MessagingService::class)]
#[CoversClass(PdoConversationRepository::class)]
#[CoversClass(PdoMessageRepository::class)]
final class MessagingServiceTest extends DatabaseTestCase
{
    private MessagingService $messaging;

    private PdoConversationRepository $conversations;

    private FrozenClock $clock;

    private int $sellerId;

    private int $buyerId;

    private int $listingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-03T12:00:00+00:00'));

        $this->sellerId = $this->createUser('zuechter@example.tld');
        $this->buyerId = $this->createUser('kaeufer@example.tld');
        $speciesId = $this->createSpecies();
        $this->listingId = $this->createListing($this->sellerId, $speciesId);

        $this->conversations = new PdoConversationRepository($this->database);

        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 3) . '/config/trust.php';
        $trust = new TrustConfiguration($config);

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
    }

    private function buyer(): User
    {
        return new User($this->buyerId, 'kaeufer@example.tld', 'Käufer', Role::Buyer);
    }

    private function seller(): User
    {
        return new User($this->sellerId, 'zuechter@example.tld', 'Züchter', Role::Seller);
    }

    private function openConversation(): Conversation
    {
        return $this->messaging->openConversation($this->listingId, $this->buyer(), '203.0.113.7');
    }

    public function testGespraechEntstehtEinmalProAnzeigeUndInteressent(): void
    {
        $erste = $this->openConversation();
        $zweite = $this->openConversation();

        self::assertSame($erste->id, $zweite->id);
        self::assertSame($this->sellerId, $erste->sellerId);
    }

    public function testNiemandSchreibtSichSelbst(): void
    {
        $this->expectException(MessagingException::class);

        $this->messaging->openConversation($this->listingId, $this->seller());
    }

    public function testNachrichtLandetImVerlauf(): void
    {
        $gespraech = $this->openConversation();
        $ergebnis = $this->messaging->send($gespraech, $this->buyer(), 'Ist das Tier noch da?');

        self::assertTrue($ergebnis->sent);

        $verlauf = $this->messaging->thread($this->conversations->findById($gespraech->id ?? 0) ?? $gespraech, $this->buyerId);

        self::assertCount(1, $verlauf);
        self::assertSame('Ist das Tier noch da?', $verlauf[0]->body);
        self::assertTrue($verlauf[0]->isOwn);
    }

    public function testKontaktdatenSindInDenErstenDreiNachrichtenAusgeblendet(): void
    {
        $gespraech = $this->openConversation();

        for ($i = 1; $i <= 4; ++$i) {
            $gespraech = $this->conversations->findById($gespraech->id ?? 0) ?? $gespraech;
            $this->messaging->send($gespraech, $this->buyer(), 'Meine Nummer: 0176 12345678 (Nachricht ' . $i . ')');
        }

        $verlauf = $this->messaging->thread($this->conversations->findById($gespraech->id ?? 0) ?? $gespraech, $this->buyerId);

        self::assertCount(4, $verlauf);

        foreach ([0, 1, 2] as $index) {
            self::assertTrue($verlauf[$index]->wasMasked, 'Nachricht ' . ($index + 1) . ' muss maskiert sein.');
            self::assertStringNotContainsString('12345678', $verlauf[$index]->body);
        }

        self::assertFalse($verlauf[3]->wasMasked);
        self::assertStringContainsString('12345678', $verlauf[3]->body);
    }

    /**
     * Die Maskierung ist eine Ansichtssache — die Moderation braucht das Original.
     */
    public function testDasOriginalBleibtInDerDatenbank(): void
    {
        $gespraech = $this->openConversation();
        $this->messaging->send($gespraech, $this->buyer(), 'Ruf an: 0176 12345678');

        $gespeichert = $this->database->scalar('SELECT body FROM messages WHERE conversation_id = :id', [
            'id' => $gespraech->id,
        ]);

        self::assertStringContainsString('0176 12345678', (string) $gespeichert);
    }

    public function testUnsichererZahlungswegWirdNichtZugestellt(): void
    {
        $gespraech = $this->openConversation();
        $ergebnis = $this->messaging->send($gespraech, $this->buyer(), 'Ich zahle per Western Union.');

        self::assertFalse($ergebnis->sent);
        self::assertNull($ergebnis->messageId);
        self::assertSame(0, (int) $this->database->scalar('SELECT COUNT(*) FROM messages'));
    }

    public function testVerdaechtigeNachrichtGehtDurchWirdAberMarkiert(): void
    {
        $gespraech = $this->openConversation();
        $ergebnis = $this->messaging->send($gespraech, $this->buyer(), 'Ich würde gern Vorkasse leisten.');

        self::assertTrue($ergebnis->sent);
        self::assertNotNull($this->database->scalar('SELECT flagged_reason FROM messages WHERE id = :id', [
            'id' => $ergebnis->messageId,
        ]));
    }

    public function testLeereNachrichtWirdAbgelehnt(): void
    {
        $gespraech = $this->openConversation();

        $this->expectException(MessagingException::class);

        $this->messaging->send($gespraech, $this->buyer(), "   \n  ");
    }

    public function testFremdeKoennenNichtMitschreiben(): void
    {
        $gespraech = $this->openConversation();
        $fremder = new User($this->createUser('fremd@example.tld'), 'fremd@example.tld', 'Fremd');

        $this->expectException(MessagingException::class);

        $this->messaging->send($gespraech, $fremder, 'Hallo?');
    }

    public function testRateLimitGreift(): void
    {
        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 3) . '/config/trust.php';
        $trust = new TrustConfiguration($config);

        $eng = new MessagingService(
            $this->conversations,
            new PdoMessageRepository($this->database),
            new PdoListingRepository($this->database),
            new RateLimiter(
                new PdoRateLimitRepository($this->database),
                $this->clock,
                ['nachricht.konto' => new RateLimit('nachricht.konto', 2, 3600)],
            ),
            $trust->keywordFilter(),
            $trust->contactMasker(),
            new PdoAuditLog($this->database),
            $this->clock,
        );

        $gespraech = $this->openConversation();
        $eng->send($gespraech, $this->buyer(), 'Erste Frage');
        $eng->send($gespraech, $this->buyer(), 'Zweite Frage');

        $this->expectException(RateLimitExceededException::class);

        $eng->send($gespraech, $this->buyer(), 'Dritte Frage');
    }

    public function testGelesenmarkierungGiltNurFuerFremdeNachrichten(): void
    {
        $gespraech = $this->openConversation();
        $this->messaging->send($gespraech, $this->buyer(), 'Frage vom Käufer');

        // Der Verkaeufer liest — danach ist die Nachricht des Kaeufers gelesen.
        $this->messaging->thread($this->conversations->findById($gespraech->id ?? 0) ?? $gespraech, $this->sellerId);

        self::assertSame(0, $this->conversations->unreadCount($this->sellerId));
        self::assertSame(0, $this->conversations->unreadCount($this->buyerId));
    }

    public function testUngelesenzaehlerImPostfach(): void
    {
        $gespraech = $this->openConversation();
        $this->messaging->send($gespraech, $this->buyer(), 'Erste Frage');

        self::assertSame(1, $this->conversations->unreadCount($this->sellerId));

        $postfach = $this->conversations->inbox($this->sellerId);

        self::assertCount(1, $postfach);
        self::assertSame(1, $postfach[0]->unreadCount);
        // createUser() legt alle Konten unter demselben Anzeigenamen an.
        self::assertSame('Testnutzer', $postfach[0]->counterpartName);
        self::assertSame('Erste Frage', $postfach[0]->lastMessagePreview);
    }

    public function testHandelBrauchtBeideSeiten(): void
    {
        $gespraech = $this->openConversation();

        $nachKaeufer = $this->messaging->confirmDeal($gespraech, $this->buyerId);
        self::assertFalse($nachKaeufer->dealConfirmed());

        $nachVerkaeufer = $this->messaging->confirmDeal($nachKaeufer, $this->sellerId);
        self::assertTrue($nachVerkaeufer->dealConfirmed());
        self::assertNotNull($nachVerkaeufer->dealConfirmedAt());
    }

    /**
     * Regressionstest: Die laufende Nummer kam frueher aus dem Speicher
     * (messageCount + 1). Mit einem veralteten Objekt — Doppelklick, zweiter
     * Browsertab — vergab sie zweimal dieselbe Nummer und der eindeutige Index
     * warf einen Serverfehler, statt die Nachricht zu senden.
     */
    public function testZweiSendungenMitDemselbenObjektGehenDurch(): void
    {
        $gespraech = $this->openConversation();

        $this->messaging->send($gespraech, $this->buyer(), 'Erste Frage');
        $this->messaging->send($gespraech, $this->buyer(), 'Zweite Frage');

        $nummern = $this->database->select(
            'SELECT sequence FROM messages WHERE conversation_id = :id ORDER BY sequence',
            ['id' => $gespraech->id],
        );

        self::assertSame([1, 2], array_map(static fn(array $row): int => (int) $row['sequence'], $nummern));
    }

    public function testDoppelteBestaetigungAendertNichts(): void
    {
        $gespraech = $this->openConversation();

        $einmal = $this->messaging->confirmDeal($gespraech, $this->buyerId);
        $zweimal = $this->messaging->confirmDeal($einmal, $this->buyerId);

        self::assertEquals($einmal->dealConfirmedBuyerAt, $zweimal->dealConfirmedBuyerAt);
    }
}
