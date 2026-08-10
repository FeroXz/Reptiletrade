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
     * @param bool                              $allowInsecureAuth Zugangsdaten auch ohne Verschluesselung senden.
     *                                                             Nur fuer einen Relay auf 127.0.0.1 gedacht
     * @param (Closure(resource): (resource|null))|null $connector Ersetzt den Aufbau der
     *                                                             Verbindung — im Test steht dort ein Socket-Paar
     *                                                             statt eines echten Servers. Er bekommt den
     *                                                             fertigen Stream-Kontext, damit sich pruefen
     *                                                             laesst, womit verbunden wuerde
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
        private bool $allowInsecureAuth = false,
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
        // Der Kontext steht vor der Weiche: Ein Test soll sehen koennen, mit
        // welchen Pruefoptionen verbunden wuerde, ohne dafuer einen echten
        // Server mit Zertifikat zu brauchen.
        $kontext = stream_context_create(['ssl' => self::tlsOptions($this->host)]);

        if ($this->connector !== null) {
            return ($this->connector)($kontext);
        }

        $schema = $this->encryption === self::ENCRYPTION_TLS ? 'ssl' : 'tcp';
        $fehlerNummer = 0;
        $fehlerText = '';

        $socket = @stream_socket_client(
            \sprintf('%s://%s:%d', $schema, $this->host, $this->port),
            $fehlerNummer,
            $fehlerText,
            $this->timeout,
            \STREAM_CLIENT_CONNECT,
            $kontext,
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
     * Die TLS-Pruefung — im Code und nicht in der php.ini.
     *
     * Ohne eigenen Kontext entscheiden openssl.cafile, verify_peer und
     * Verwandte des Servers darueber, ob das Zertifikat des Mailservers
     * ueberhaupt geprueft wird. Auf einem sauber eingerichteten Debian geht das
     * gut, auf einem Server mit abweichender php.ini schweigend nicht — und
     * eine Sicherheitszusage, die von einer Datei ausserhalb dieses
     * Verzeichnisses abhaengt, ist keine.
     *
     * peer_name ist der **konfigurierte** Hostname, nicht eine aufgeloeste
     * Adresse: Hinter einem Lastverteiler antwortet sonst eine IP, auf die kein
     * Zertifikat ausgestellt ist, und die Pruefung schlaege bei jedem
     * ordentlichen Server fehl.
     *
     * @return array<string, string|bool>
     */
    private static function tlsOptions(string $host): array
    {
        return [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ];
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

            // Die Pruefoptionen noch einmal auf den Socket: Der Kontext des
            // Verbindungsaufbaus gilt fuer die Sitzung, aber der Handschlag
            // findet hier statt — und ohne peer_name pruefte er gegen nichts.
            stream_context_set_options($socket, ['ssl' => self::tlsOptions($this->host)]);

            $erfolg = @stream_socket_enable_crypto(
                $socket,
                true,
                \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            );

            if ($erfolg !== true) {
                // Kein Rueckfall auf Klartext: Wer STARTTLS eingestellt hat,
                // will keine Zugangsdaten im Klartext ueber die Leitung. Das
                // gilt auch fuer ein Zertifikat, das die Pruefung nicht
                // besteht — die Mail bleibt dann im Postausgang, und der
                // Auftrag wiederholt sie, wenn der Server wieder in Ordnung ist.
                throw new RuntimeException('STARTTLS ist fehlgeschlagen.');
            }

            // Nach STARTTLS gilt die Faehigkeitsliste von vorher nicht mehr —
            // AUTH bieten viele Server erst nach der Verschluesselung an.
            $faehigkeiten = $this->command($socket, 'EHLO ' . $name, 250);
        }

        if ($this->username !== '') {
            $this->guardAuthentication($this->encryption !== self::ENCRYPTION_NONE, $faehigkeiten);
            $this->authenticate($socket, $faehigkeiten);
        }

        $this->command($socket, \sprintf('MAIL FROM:<%s>', self::sanitizeHeader($this->fromAddress)), 250);
        $this->command($socket, \sprintf('RCPT TO:<%s>', $to), 250, 251);
        $this->command($socket, 'DATA', 354);
        $this->command($socket, $this->payload($to, $message) . "\r\n.", 250);
    }

    /**
     * Darf ueberhaupt authentifiziert werden?
     *
     * Ohne Verschluesselung gehen Benutzername und Passwort als Base64 ueber
     * die Leitung — Base64 ist keine Verschluesselung, sondern eine
     * Schreibweise. Deshalb bricht der Versand hier ab, statt still zu
     * senden: Die Mail bleibt im Postausgang und wird spaeter wiederholt,
     * die Zugangsdaten bleiben drinnen. Ein stiller Versand waere der
     * schlechtere Tausch — er kostet die Zugangsdaten dauerhaft und faellt
     * niemandem auf.
     *
     * Die Ausnahme muss ausdruecklich gesetzt werden (SMTP_ALLOW_INSECURE_AUTH)
     * und ist fuer den einen Fall gedacht, in dem sie vertretbar ist: ein
     * Relay auf 127.0.0.1, bei dem es keine Leitung gibt, auf der jemand
     * mithoeren koennte.
     *
     * Und wenn der Server AUTH gar nicht anbietet, wird es erst nicht
     * versucht: Die Faehigkeitsliste liegt nach dem EHLO vor, und ein
     * "AUTH LOGIN" ins Blaue bekaeme entweder eine Ablehnung oder — bei einem
     * nachlaessigen Server — die Zugangsdaten trotzdem aus dem Haus.
     */
    private function guardAuthentication(bool $encrypted, string $capabilities): void
    {
        if (!$encrypted && !$this->allowInsecureAuth) {
            throw new RuntimeException(
                'SMTP: Zugangsdaten ohne Verschluesselung. Der Versand bricht ab, statt Benutzername und '
                . 'Passwort im Klartext zu senden. SMTP_ENCRYPTION=starttls setzen — oder, nur fuer einen '
                . 'Relay auf 127.0.0.1, SMTP_ALLOW_INSECURE_AUTH=true.',
            );
        }

        // Auf eine eigene Zeile gebunden: "250-AUTH PLAIN LOGIN" zaehlt,
        // ein Server-Text, in dem das Wort zufaellig vorkommt, nicht.
        if (preg_match('/^\d{3}[ -]AUTH[ =]/mi', $capabilities) !== 1) {
            throw new RuntimeException(
                'SMTP: Der Server bietet kein AUTH an, es sind aber Zugangsdaten eingestellt. '
                . 'Entweder ist der falsche Port eingestellt oder der Server erwartet gar keine Anmeldung.',
            );
        }
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

        foreach (MailHeaders::additional($message) as $name => $value) {
            $kopf[] = $name . ': ' . $value;
        }

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
