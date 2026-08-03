<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Mail;

use Reptilienmarkt\Domain\Mail\Mailer;
use Reptilienmarkt\Domain\Mail\MailMessage;

/**
 * Schreibt Mails in Dateien statt sie zu versenden.
 *
 * Das ist die Voreinstellung fuer Entwicklung und Tests: Ein Reset-Link laesst
 * sich so nachlesen, ohne dass irgendwo eine Mail landet. Fuer den Betrieb
 * tritt eine SMTP-Umsetzung an diese Stelle — dafuer gibt es das Interface.
 */
final readonly class FileMailer implements Mailer
{
    public function __construct(private string $directory) {}

    public function send(MailMessage $message): bool
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            return false;
        }

        $file = \sprintf(
            '%s/%s-%s.txt',
            $this->directory,
            gmdate('Ymd-His'),
            substr(hash('sha256', $message->to . $message->subject . microtime()), 0, 8),
        );

        $content = \sprintf(
            "An: %s\nBetreff: %s\nZeitpunkt: %s\n\n%s\n",
            $message->recipient(),
            $message->subject,
            gmdate('c'),
            $message->body,
        );

        return file_put_contents($file, $content) !== false;
    }
}
