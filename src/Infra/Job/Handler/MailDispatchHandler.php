<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Job\Handler;

use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobException;
use Reptilienmarkt\Domain\Job\JobHandler;
use Reptilienmarkt\Domain\Mail\Mailer;
use Reptilienmarkt\Domain\Mail\MailOutboxRepository;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Log\Logger;
use Throwable;

/**
 * Stellt zu, was im Postausgang liegt.
 *
 * Der Handler bekommt den **Transport** (sendmail, SMTP oder die Dateiablage),
 * nicht den Standard-Mailer — sonst schriebe er die Mail nur wieder in die
 * Tabelle, aus der er sie gerade geholt hat.
 *
 * Wiederholt wird ueber den Backoff des JobRunners: Scheitert in einem Lauf
 * mindestens eine Zustellung, die noch Versuche hat, endet der Auftrag mit
 * einem Fehler und wird mit wachsendem Abstand erneut versucht. Ein eigener
 * Zeitplan je Zeile waere ein zweiter Backoff neben dem vorhandenen — mit dem
 * Ergebnis, dass beide gegeneinander warten.
 *
 * Idempotent ueber den Status: Eine gesendete Zeile taucht in due() nicht mehr
 * auf, ein zweiter Lauf verschickt sie also nicht noch einmal.
 */
final readonly class MailDispatchHandler implements JobHandler
{
    /**
     * Wie oft eine einzelne Mail versucht wird, bevor sie aufgegeben wird.
     *
     * Deutlich mehr als die drei Versuche des Auftrags selbst: Die Versuche
     * einer Mail summieren sich ueber mehrere Auftraege hinweg, und ein MTA,
     * der eine halbe Stunde weg ist, soll nicht dazu fuehren, dass jede
     * Bestaetigungsmail dieser halben Stunde verloren ist.
     */
    private const int MAX_ATTEMPTS = 10;

    /**
     * Wie viele Mails ein Lauf hoechstens anfasst. Der Worker soll zwischen
     * zwei Auftraegen atmen koennen.
     */
    private const int BATCH = 50;

    public function __construct(
        private MailOutboxRepository $outbox,
        private Mailer $transport,
        private Clock $clock,
        private Logger $logger,
    ) {}

    public function type(): string
    {
        return 'mail.dispatch';
    }

    public function handle(Job $job): string
    {
        $gesendet = 0;
        $wiederholbar = 0;
        $aufgegeben = 0;
        $letzterFehler = '';

        foreach ($this->outbox->due(self::BATCH) as $eintrag) {
            $now = $this->clock->now();

            try {
                $erfolg = $this->transport->send($eintrag->message);
                $fehler = $erfolg ? '' : 'Der Transport hat die Zustellung abgelehnt.';
            } catch (Throwable $exception) {
                // Ein Transport darf laut Interface nicht werfen — wenn er es
                // doch tut, ist das ein Fehlschlag wie jeder andere und darf
                // die uebrigen Mails des Stapels nicht mitreissen.
                $erfolg = false;
                $fehler = $exception::class . ': ' . $exception->getMessage();
            }

            if ($erfolg) {
                $this->outbox->markSent($eintrag->id, $now);
                ++$gesendet;

                continue;
            }

            $letzterFehler = $fehler;

            if ($eintrag->attempts + 1 >= self::MAX_ATTEMPTS) {
                $this->outbox->markFailed($eintrag->id, $fehler, $now);
                ++$aufgegeben;

                $this->logger->error('mail.aufgegeben', [
                    'mail_id' => $eintrag->id,
                    'zweck' => $eintrag->message->purpose,
                    'versuche' => $eintrag->attempts + 1,
                    'fehler' => $fehler,
                ]);

                continue;
            }

            $this->outbox->markRetry($eintrag->id, $fehler, $now);
            ++$wiederholbar;
        }

        $bericht = \sprintf(
            '%d zugestellt, %d zur Wiederholung, %d aufgegeben',
            $gesendet,
            $wiederholbar,
            $aufgegeben,
        );

        // Nur wiederholbare Fehlschlaege lassen den Auftrag scheitern: Sie
        // sind die, denen ein spaeterer Versuch noch helfen kann. Aufgegebene
        // Mails wuerden den Auftrag sonst bei jedem Lauf erneut rot faerben,
        // obwohl sich nichts mehr aendert — sie stehen im Dashboard.
        if ($wiederholbar > 0) {
            throw new JobException(\sprintf('%s — zuletzt: %s', $bericht, $letzterFehler));
        }

        return $bericht;
    }
}
