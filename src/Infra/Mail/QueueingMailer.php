<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Mail;

use Reptilienmarkt\Domain\Mail\Mailer;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Domain\Mail\MailOutboxRepository;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Log\Logger;
use Throwable;

/**
 * Der Standardweg: einreihen statt zustellen.
 *
 * Ein Request soll nicht an einem fremden Dienst haengen. Der Transport —
 * lokaler MTA oder SMTP — antwortet im schlechten Fall erst nach Sekunden oder
 * gar nicht; die Registrierung wuerde dann so lange stehen und im Zweifel im
 * Zeitlimit des Webservers enden, obwohl das Konto laengst angelegt ist.
 *
 * Hier wird nur eine Zeile geschrieben. Der Auftrag mail.dispatch stellt
 * spaeter zu und wiederholt bei Fehlschlag. Das kostet Zustellzeit — in der
 * Regel Sekunden bis wenige Minuten — und ist der Preis dafuer, dass keine
 * Mail mehr verloren geht.
 */
final readonly class QueueingMailer implements Mailer
{
    public function __construct(
        private MailOutboxRepository $outbox,
        private Clock $clock,
        private Logger $logger,
    ) {}

    public function send(MailMessage $message): bool
    {
        // Eine unbrauchbare Adresse gar nicht erst einreihen: Sie wuerde den
        // Postausgang mit Zeilen fuellen, die jeder Versuch neu ablehnt.
        if (filter_var($message->to, \FILTER_VALIDATE_EMAIL) === false) {
            $this->logger->warning('mail.ungueltige_adresse', ['zweck' => $message->purpose]);

            return false;
        }

        try {
            $id = $this->outbox->queue($message, $this->clock->now());
        } catch (Throwable $exception) {
            // Auch das darf den Vorgang nicht abbrechen — dieselbe Zusage wie
            // im Interface. Nur wissen muss man davon.
            $this->logger->error('mail.einreihen_fehlgeschlagen', [
                'zweck' => $message->purpose,
                'fehler' => $exception::class . ': ' . $exception->getMessage(),
            ]);

            return false;
        }

        $this->logger->info('mail.eingereiht', ['mail_id' => $id, 'zweck' => $message->purpose]);

        return true;
    }
}
