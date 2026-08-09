<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Mail;

use Reptilienmarkt\Domain\Mail\Mailer;
use Reptilienmarkt\Domain\Mail\MailMessage;

/**
 * Versand ueber die PHP-Funktion mail(), also ueber den lokalen MTA. Das ist
 * die schlichteste Variante, die auf einem Debian-LAMP-Server ohne weitere
 * Einrichtung funktioniert.
 *
 * Kopfzeilen werden gegen eingeschleuste Zeilenumbrueche geprueft: Ein
 * Zeilenumbruch im Betreff oder in der Adresse waere eine Header-Injection.
 */
final readonly class SendmailMailer implements Mailer
{
    public function __construct(
        private string $fromAddress,
        private string $fromName,
    ) {}

    public function send(MailMessage $message): bool
    {
        $to = self::sanitizeHeader($message->to);
        $subject = self::sanitizeHeader($message->subject);

        if ($to === '' || filter_var($to, \FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $headers = [
            'From: ' . self::sanitizeHeader($this->fromName) . ' <' . self::sanitizeHeader($this->fromAddress) . '>',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
            'MIME-Version: 1.0',
        ];

        foreach (MailHeaders::additional($message) as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        return @mail($to, $subject, $message->body, implode("\r\n", $headers));
    }

    private static function sanitizeHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }
}
