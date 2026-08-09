<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Mail;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Domain\Mail\MailOutboxEntry;
use Reptilienmarkt\Domain\Mail\MailOutboxRepository;
use Reptilienmarkt\Infra\Mail\QueueingMailer;
use Reptilienmarkt\Infra\Persistence\PdoMailOutboxRepository;
use Reptilienmarkt\Support\Log\NullLogger;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;
use RuntimeException;

#[CoversClass(QueueingMailer::class)]
#[CoversClass(PdoMailOutboxRepository::class)]
final class QueueingMailerTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-09T10:00:00Z'));
    }

    public function testDerVersandLegtNurEineZeileAnUndBeruehrtKeinenTransport(): void
    {
        $userId = $this->createUser('kaeufer@example.tld');

        $erfolg = $this->mailer()->send(new MailMessage(
            'kaeufer@example.tld',
            'Bitte bestätige deine E-Mail-Adresse',
            'Hallo,\n\nhier ist dein Link.',
            'Testnutzer',
            'konto.verify',
            $userId,
        ));

        self::assertTrue($erfolg);

        $zeile = $this->database->selectOne('SELECT * FROM mail_outbox');

        self::assertNotNull($zeile);
        self::assertSame('kaeufer@example.tld', $zeile['recipient']);
        self::assertSame('konto.verify', $zeile['purpose']);
        self::assertSame($userId, (int) $zeile['user_id']);
        self::assertSame('wartend', $zeile['status']);
        self::assertSame(0, (int) $zeile['attempts']);
    }

    /**
     * Der eigentliche Zweck des Postausgangs: Ein toter MTA darf die
     * Registrierung nicht mit in den Abgrund ziehen.
     */
    public function testEinToterTransportLaesstDenVorgangUnberuehrt(): void
    {
        $ausgang = new PdoMailOutboxRepository($this->database);

        $erfolg = $this->mailer()->send(new MailMessage('kaeufer@example.tld', 'Betreff', 'Text'));

        self::assertTrue($erfolg);
        // Zugestellt wurde nichts — der Transport wurde nie gefragt.
        self::assertCount(1, $ausgang->due());
    }

    /**
     * Auch ein Schreibfehler im Postausgang darf nur ein "false" sein — das
     * Interface verspricht, dass der Aufrufer weiterlaeuft.
     */
    public function testEinUnerreichbarerPostausgangWirftNicht(): void
    {
        $mailer = new QueueingMailer($this->kaputterAusgang(), $this->clock, new NullLogger());

        self::assertFalse($mailer->send(new MailMessage('kaeufer@example.tld', 'Betreff', 'Text')));
    }

    public function testEineUnbrauchbareAdresseWirdGarNichtErstEingereiht(): void
    {
        self::assertFalse($this->mailer()->send(new MailMessage('kein-at-zeichen', 'Betreff', 'Text')));
        self::assertSame(0, (int) (string) $this->database->scalar('SELECT COUNT(*) FROM mail_outbox'));
    }

    private function mailer(): QueueingMailer
    {
        return new QueueingMailer(new PdoMailOutboxRepository($this->database), $this->clock, new NullLogger());
    }

    private function kaputterAusgang(): MailOutboxRepository
    {
        return new class implements MailOutboxRepository {
            public function queue(MailMessage $message, DateTimeImmutable $now): int
            {
                throw new RuntimeException('Die Datenbank ist weg.');
            }

            public function due(int $limit = 50): array
            {
                return [];
            }

            public function markSent(int $id, DateTimeImmutable $at): void {}

            public function markRetry(int $id, string $error, DateTimeImmutable $at): void {}

            public function markFailed(int $id, string $error, DateTimeImmutable $at): void {}

            public function countPendingBefore(DateTimeImmutable $before): int
            {
                return 0;
            }

            /**
             * @return list<MailOutboxEntry>
             */
            public function recentFailures(int $limit = 10): array
            {
                return [];
            }
        };
    }
}
