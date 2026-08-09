<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Mail;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Infra\Mail\SmtpMailer;
use Reptilienmarkt\Support\Log\NullLogger;

/**
 * Getestet wird gegen einen Socket-Doppelgaenger: ein Socket-Paar, in dessen
 * eines Ende die Antworten des Servers vorab geschrieben werden.
 *
 * Warum vorab und nicht im Wechselspiel: Der Mailer ist blockierend, ein
 * echter Server im selben Prozess wuerde sich mit ihm verklemmen. Da das
 * Drehbuch feststeht, reicht der gefuellte Puffer — und der Test kommt ohne
 * zweiten Prozess und ohne freien Netzwerkport aus.
 */
#[CoversClass(SmtpMailer::class)]
final class SmtpMailerTest extends TestCase
{
    public function testDerDialogFolgtDemProtokoll(): void
    {
        [$client, $server] = $this->socketPaar([
            '220 mail.example.tld ESMTP bereit',
            '250-mail.example.tld',
            '250-AUTH PLAIN LOGIN',
            '250 SIZE 10240000',
            '235 2.7.0 Authentifiziert',
            '250 2.1.0 Absender angenommen',
            '250 2.1.5 Empfaenger angenommen',
            '354 Text senden, Ende mit <CRLF>.<CRLF>',
            '250 2.0.0 Angenommen',
        ]);

        $erfolg = $this->mailer($client, 'benutzer', 'geheim')->send(new MailMessage(
            'kaeufer@example.tld',
            'Grüße vom Reptilienmarkt',
            "Hallo,\n.Punktzeile\nBis bald.",
            'Käufer',
        ));

        self::assertTrue($erfolg);

        $protokoll = $this->mitschnitt($server);

        self::assertStringContainsString('EHLO ', $protokoll);
        self::assertStringContainsString('AUTH PLAIN ' . base64_encode("\0benutzer\0geheim"), $protokoll);
        self::assertStringContainsString('MAIL FROM:<noreply@example.tld>', $protokoll);
        self::assertStringContainsString('RCPT TO:<kaeufer@example.tld>', $protokoll);
        self::assertStringContainsString("DATA\r\n", $protokoll);
        self::assertStringContainsString('QUIT', $protokoll);

        // Umlaute im Betreff muessen codiert werden — roher UTF-8 im Kopf ist
        // kein gueltiger Header.
        self::assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Grüße vom Reptilienmarkt') . '?=', $protokoll);

        // Eine Zeile, die mit einem Punkt beginnt, wuerde sonst den Text beenden.
        self::assertStringContainsString("\r\n..Punktzeile\r\n", $protokoll);
        self::assertStringContainsString("\r\n.\r\n", $protokoll);
    }

    public function testOhneZugangsdatenWirdNichtAuthentifiziert(): void
    {
        [$client, $server] = $this->socketPaar([
            '220 mail.example.tld ESMTP bereit',
            '250 mail.example.tld',
            '250 2.1.0 Absender angenommen',
            '250 2.1.5 Empfaenger angenommen',
            '354 Text senden',
            '250 2.0.0 Angenommen',
        ]);

        self::assertTrue($this->mailer($client)->send(new MailMessage('kaeufer@example.tld', 'Betreff', 'Text')));
        self::assertStringNotContainsString('AUTH', $this->mitschnitt($server));
    }

    public function testEineAblehnungDesEmpfaengersGiltAlsFehlschlag(): void
    {
        [$client, $server] = $this->socketPaar([
            '220 mail.example.tld ESMTP bereit',
            '250 mail.example.tld',
            '250 2.1.0 Absender angenommen',
            '550 5.1.1 Empfaenger unbekannt',
        ]);

        self::assertFalse($this->mailer($client)->send(new MailMessage('kaeufer@example.tld', 'Betreff', 'Text')));
        // Kein DATA nach einer Ablehnung: Der Mailer bricht ab, statt den Text
        // gegen eine geschlossene Tuer zu schicken.
        self::assertStringNotContainsString("DATA\r\n", $this->mitschnitt($server));
    }

    public function testEinSchweigenderServerGiltAlsFehlschlag(): void
    {
        [$client, $server] = $this->socketPaar([]);
        fclose($server);

        self::assertFalse($this->mailer($client)->send(new MailMessage('kaeufer@example.tld', 'Betreff', 'Text')));
    }

    public function testEineUnbrauchbareAdresseOeffnetKeineVerbindung(): void
    {
        $mailer = new SmtpMailer(
            'mail.example.tld',
            25,
            'noreply@example.tld',
            'Reptilienmarkt',
            new NullLogger(),
            connector: static function (): mixed {
                self::fail('Fuer eine unbrauchbare Adresse darf keine Verbindung aufgebaut werden.');
            },
        );

        self::assertFalse($mailer->send(new MailMessage('kein-at-zeichen', 'Betreff', 'Text')));
    }

    /**
     * @param resource $client
     */
    private function mailer($client, string $benutzer = '', string $passwort = ''): SmtpMailer
    {
        return new SmtpMailer(
            'mail.example.tld',
            25,
            'noreply@example.tld',
            'Reptilienmarkt',
            new NullLogger(),
            $benutzer,
            $passwort,
            // Ein Socket-Paar kennt kein TLS; geprueft wird der Dialog.
            SmtpMailer::ENCRYPTION_NONE,
            5,
            static fn(): mixed => $client,
        );
    }

    /**
     * @param list<string> $antworten
     *
     * @return array{resource, resource}
     */
    private function socketPaar(array $antworten): array
    {
        $paar = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, 0);

        self::assertIsArray($paar);

        if ($antworten !== []) {
            fwrite($paar[1], implode("\r\n", $antworten) . "\r\n");
        }

        return [$paar[0], $paar[1]];
    }

    /**
     * @param resource $server
     */
    private function mitschnitt($server): string
    {
        // Der Mailer hat sein Ende geschlossen, also steht das Dateiende an —
        // stream_get_contents kehrt zurueck, statt zu warten.
        $inhalt = stream_get_contents($server);

        return $inhalt === false ? '' : $inhalt;
    }
}
