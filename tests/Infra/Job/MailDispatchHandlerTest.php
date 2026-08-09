<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Job;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobException;
use Reptilienmarkt\Domain\Mail\Mailer;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Infra\Job\Handler\MailDispatchHandler;
use Reptilienmarkt\Infra\Mail\QueueingMailer;
use Reptilienmarkt\Infra\Persistence\PdoMailOutboxRepository;
use Reptilienmarkt\Support\Log\NullLogger;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\CollectingMailer;
use Reptilienmarkt\Tests\Support\FrozenClock;
use RuntimeException;

#[CoversClass(MailDispatchHandler::class)]
final class MailDispatchHandlerTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private PdoMailOutboxRepository $outbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-09T10:00:00Z'));
        $this->outbox = new PdoMailOutboxRepository($this->database);
    }

    public function testNachEinemFehlschlagWirdErneutZugestelltUndDanachNichtDoppelt(): void
    {
        $this->einreihen();

        // Erster Lauf: Der Transport lehnt ab. Der Auftrag scheitert, damit
        // der Backoff des JobRunners den naechsten Versuch verzoegert.
        try {
            $this->handler($this->abweisenderTransport())->handle(new Job(1, 'mail.dispatch'));
            self::fail('Ein abgelehnter Versand muss den Auftrag scheitern lassen.');
        } catch (JobException $exception) {
            self::assertStringContainsString('1 zur Wiederholung', $exception->getMessage());
        }

        self::assertSame('wartend', $this->spalte('status'));
        self::assertSame('1', $this->spalte('attempts'));

        // Zweiter Lauf: Der Transport ist wieder da.
        $transport = new CollectingMailer();
        $bericht = $this->handler($transport)->handle(new Job(2, 'mail.dispatch'));

        self::assertSame('1 zugestellt, 0 zur Wiederholung, 0 aufgegeben', $bericht);
        self::assertCount(1, $transport->messages());
        self::assertSame('gesendet', $this->spalte('status'));

        // Dritter Lauf: nichts mehr zu tun — eine gesendete Zeile darf nicht
        // ein zweites Mal hinausgehen.
        $transport->clear();
        $bericht = $this->handler($transport)->handle(new Job(3, 'mail.dispatch'));

        self::assertSame('0 zugestellt, 0 zur Wiederholung, 0 aufgegeben', $bericht);
        self::assertSame([], $transport->messages());
    }

    public function testEineWerfendeZustellungReisstDieUebrigenNichtMit(): void
    {
        $this->einreihen('erste@example.tld');
        $this->einreihen('zweite@example.tld');

        $transport = new class implements Mailer {
            /** @var list<string> */
            public array $zugestellt = [];

            public function send(MailMessage $message): bool
            {
                if ($message->to === 'erste@example.tld') {
                    throw new RuntimeException('Verbindung abgerissen.');
                }

                $this->zugestellt[] = $message->to;

                return true;
            }
        };

        try {
            $this->handler($transport)->handle(new Job(1, 'mail.dispatch'));
            self::fail('Der Auftrag muss scheitern, solange eine Mail offen ist.');
        } catch (JobException) {
            // erwartet
        }

        self::assertSame(['zweite@example.tld'], $transport->zugestellt);
        self::assertStringContainsString(
            'Verbindung abgerissen.',
            (string) $this->database->scalar("SELECT last_error FROM mail_outbox WHERE recipient = 'erste@example.tld'"),
        );
    }

    /**
     * Aufgegebene Mails bleiben stehen und faerben den Auftrag nicht dauerhaft
     * rot — sonst waere das Dashboard nach einer einzigen kaputten Adresse
     * bei jedem Lauf voll.
     */
    public function testNachZuVielenVersuchenWirdAufgegeben(): void
    {
        $id = $this->einreihen();
        $this->database->execute('UPDATE mail_outbox SET attempts = 9 WHERE id = :id', ['id' => $id]);

        $bericht = $this->handler($this->abweisenderTransport())->handle(new Job(1, 'mail.dispatch'));

        self::assertSame('0 zugestellt, 0 zur Wiederholung, 1 aufgegeben', $bericht);
        self::assertSame('fehlgeschlagen', $this->spalte('status'));
        self::assertCount(1, $this->outbox->recentFailures());
        // Und sie taucht nicht wieder auf.
        self::assertSame([], $this->outbox->due());
    }

    public function testDerEingereihteTextGehtUnveraendertHinaus(): void
    {
        $mailer = new QueueingMailer($this->outbox, $this->clock, new NullLogger());
        $mailer->send(new MailMessage('kaeufer@example.tld', 'Betreff', "Zeile eins\nZeile zwei", 'Käufer', 'konto.verify'));

        $transport = new CollectingMailer();
        $this->handler($transport)->handle(new Job(1, 'mail.dispatch'));

        $mail = $transport->messages()[0];

        self::assertSame('kaeufer@example.tld', $mail->to);
        self::assertSame('Käufer', $mail->toName);
        self::assertSame("Zeile eins\nZeile zwei", $mail->body);
        self::assertSame('konto.verify', $mail->purpose);
    }

    private function handler(Mailer $transport): MailDispatchHandler
    {
        return new MailDispatchHandler($this->outbox, $transport, $this->clock, new NullLogger());
    }

    private function einreihen(string $empfaenger = 'kaeufer@example.tld'): int
    {
        return $this->outbox->queue(
            new MailMessage($empfaenger, 'Betreff', 'Text', null, 'konto.verify'),
            $this->clock->now(),
        );
    }

    private function abweisenderTransport(): Mailer
    {
        return new class implements Mailer {
            public function send(MailMessage $message): bool
            {
                return false;
            }
        };
    }

    private function spalte(string $name): string
    {
        return (string) $this->database->scalar(\sprintf('SELECT %s FROM mail_outbox ORDER BY id LIMIT 1', $name));
    }
}
