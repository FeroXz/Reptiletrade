<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Mail;

use Closure;
use Reptilienmarkt\Domain\Mail\Mailer;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Support\Log\Logger;
use RuntimeException;
use Throwable;

/**
 * Versand ueber einen SMTP-Server, in reinem PHP.
 *
 * Warum ohne Bibliothek: Der Sprachumfang, den ein Absender braucht, ist klein
 * — EHLO, STARTTLS, AUTH, MAIL/RCPT/DATA. Dafuer eine Abhaengigkeit samt
 * Aktualisierungspflicht einzukaufen, steht in keinem Verhaeltnis; die
 * Anwendung kommt bis hierher ohne Fremdcode aus, und das bleibt so.
 *
 * Dieser Mailer ist ein **Transport**: Er wird vom Auftrag mail.dispatch
 * benutzt, nicht aus einem Request heraus. Ein blockierender Socket ist im
 * Worker in Ordnung und im Request nicht.
 */
final readonly class SmtpMailer implements Mailer
{
    public const string ENCRYPTION_NONE = 'keine';
    public const string ENCRYPTION_STARTTLS = 'starttls';
    public const string ENCRYPTION_TLS = 'tls';

    /**
     * @param (Closure(): (resource|null))|null $connector Ersetzt den Aufbau der
     *                                                     Verbindung — im Test steht dort ein Socket-Paar
     *                                                     statt eines echten Servers
     */
    public function __construct(
        private string $host,
        private int $port,
        private string $fromAddress,
        private string $fromName,
        private Logger $logger,
        private string $username = '',
        private string $password = '',
        private string $encryption = self::ENCRYPTION_STARTTLS,
        private int $timeout = 10,
        private ?Closure $connector = null,
    ) {}

    public function send(MailMessage $message): bool
    {
        $to = self::sanitizeHeader($message->to);

        if ($to === '' || filter_var($to, \FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $socket = $this->connect();

        if ($socket === null) {
            return false;
        }

        try {
            $this->dialogue($socket, $to, $message);

            return true;
        } catch (Throwable $exception) {
            $this->logger->error('mail.smtp_fehlgeschlagen', [
                'host' => $this->host,
                'port' => $this->port,
                'zweck' => $message->purpose,
                'fehler' => $exception->getMessage(),
            ]);

            return false;
        } finally {
            // Ein QUIT ist Hoeflichkeit, kein Muss: Scheitert es, ist die
            // Verbindung ohnehin hin, und das Schliessen erledigt den Rest.
            try {
                $this->write($socket, 'QUIT');
            } catch (Throwable) {
                // bewusst leer
            }

            fclose($socket);
        }
    }

    /**
     * @return resource|null
     */
    private function connect()
    {
        if ($this->connector !== null) {
            return ($this->connector)();
        }

        $schema = $this->encryption === self::ENCRYPTION_TLS ? 'ssl' : 'tcp';
        $fehlerNummer = 0;
        $fehlerText = '';

        $socket = @stream_socket_client(
            \sprintf('%s://%s:%d', $schema, $this->host, $this->port),
            $fehlerNummer,
            $fehlerText,
            $this->timeout,
        );

        if ($socket === false) {
            $this->logger->error('mail.smtp_verbindung', [
                'host' => $this->host,
                'port' => $this->port,
                'fehler' => $fehlerText === '' ? 'unbekannt' : $fehlerText,
            ]);

            return null;
        }

        stream_set_timeout($socket, $this->timeout);

        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function dialogue($socket, string $to, MailMessage $message): void
    {
        $this->expect($socket, 220);

        $name = self::sanitizeHeader((string) (gethostname() ?: 'localhost'));
        $faehigkeiten = $this->command($socket, 'EHLO ' . $name, 250);

        if ($this->encryption === self::ENCRYPTION_STARTTLS) {
            $this->command($socket, 'STARTTLS', 220);

            $erfolg = @stream_socket_enable_crypto(
                $socket,
                true,
                \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            );

            if ($erfolg !== true) {
                // Kein Rueckfall auf Klartext: Wer STARTTLS eingestellt hat,
                // will keine Zugangsdaten im Klartext ueber die Leitung.
                throw new RuntimeException('STARTTLS ist fehlgeschlagen.');
            }

            // Nach STARTTLS gilt die Faehigkeitsliste von vorher nicht mehr —
            // AUTH bieten viele Server erst nach der Verschluesselung an.
            $faehigkeiten = $this->command($socket, 'EHLO ' . $name, 250);
        }

        if ($this->username !== '') {
            $this->authenticate($socket, $faehigkeiten);
        }

        $this->command($socket, \sprintf('MAIL FROM:<%s>', self::sanitizeHeader($this->fromAddress)), 250);
        $this->command($socket, \sprintf('RCPT TO:<%s>', $to), 250, 251);
        $this->command($socket, 'DATA', 354);
        $this->command($socket, $this->payload($to, $message) . "\r\n.", 250);
    }

    /**
     * @param resource $socket
     */
    private function authenticate($socket, string $capabilities): void
    {
        // PLAIN, wenn der Server es nennt: ein Austausch statt dreier.
        if (stripos($capabilities, 'PLAIN') !== false) {
            $this->command(
                $socket,
                'AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password),
                235,
            );

            return;
        }

        $this->command($socket, 'AUTH LOGIN', 334);
        $this->command($socket, base64_encode($this->username), 334);
        $this->command($socket, base64_encode($this->password), 235);
    }

    /**
     * Kopf und Text der Mail, fertig fuer DATA.
     */
    private function payload(string $to, MailMessage $message): string
    {
        $absender = \sprintf(
            '%s <%s>',
            self::encodeHeader(self::sanitizeHeader($this->fromName)),
            self::sanitizeHeader($this->fromAddress),
        );

        $empfaenger = $message->toName === null
            ? $to
            : \sprintf('%s <%s>', self::encodeHeader(self::sanitizeHeader($message->toName)), $to);

        $kopf = [
            'From: ' . $absender,
            'To: ' . $empfaenger,
            'Subject: ' . self::encodeHeader(self::sanitizeHeader($message->subject)),
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: ' . \sprintf('<%s@%s>', bin2hex(random_bytes(12)), self::domain($this->fromAddress)),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        return implode("\r\n", $kopf) . "\r\n\r\n" . self::stuff($message->body);
    }

    /**
     * Zeilen, die mit einem Punkt beginnen, werden verdoppelt — sonst beendet
     * so eine Zeile den DATA-Abschnitt mitten im Text.
     */
    private static function stuff(string $body): string
    {
        $normalisiert = str_replace(["\r\n", "\r"], "\n", $body);

        return implode("\r\n", array_map(
            static fn(string $zeile): string => str_starts_with($zeile, '.') ? '.' . $zeile : $zeile,
            explode("\n", $normalisiert),
        ));
    }

    /**
     * @param resource $socket
     *
     * @return string die Antwort des Servers
     */
    private function command($socket, string $command, int ...$expected): string
    {
        $this->write($socket, $command);

        return $this->expect($socket, ...$expected);
    }

    /**
     * @param resource $socket
     */
    private function write($socket, string $line): void
    {
        if (@fwrite($socket, $line . "\r\n") === false) {
            throw new RuntimeException('Die Verbindung zum SMTP-Server ist abgerissen.');
        }
    }

    /**
     * @param resource $socket
     */
    private function expect($socket, int ...$expected): string
    {
        $antwort = '';

        do {
            $zeile = fgets($socket, 1024);

            if ($zeile === false) {
                throw new RuntimeException('Der SMTP-Server hat nicht geantwortet.');
            }

            $antwort .= $zeile;
            // Ein Bindestrich an vierter Stelle heisst: es folgt noch eine Zeile.
            $weiter = \strlen($zeile) > 3 && $zeile[3] === '-';
        } while ($weiter);

        $code = (int) substr($antwort, 0, 3);

        if (!\in_array($code, $expected, true)) {
            throw new RuntimeException(\sprintf(
                'SMTP: erwartet %s, erhalten %s',
                implode('/', array_map(strval(...), $expected)),
                trim($antwort),
            ));
        }

        return $antwort;
    }

    /**
     * Nur codieren, wenn noetig: Ein reiner ASCII-Betreff bleibt im Postfach
     * und im Protokoll lesbar.
     */
    private static function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function domain(string $address): string
    {
        $teile = explode('@', $address);

        return self::sanitizeHeader($teile[1] ?? 'localhost');
    }

    private static function sanitizeHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }
}
