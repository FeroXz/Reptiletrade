<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Job\Handler;

use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobHandler;
use Reptilienmarkt\Domain\Mail\Mailer;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Domain\Notification\NotificationChannel;
use Reptilienmarkt\Domain\Privacy\RetentionPolicy;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Timestamp;
use Reptilienmarkt\Support\Translator;

/**
 * Erinnert an ablaufende Anzeigen (T-7 und T-1).
 *
 * Idempotent ueber die Tabelle selbst: Die versendete Erinnerung wird an der
 * Anzeige vermerkt, damit ein zweiter Lauf am selben Tag nicht zweimal
 * schreibt. Ohne diesen Vermerk waere jeder Neustart des Workers eine
 * Mailwelle.
 */
final readonly class ListingExpiryNoticeHandler implements JobHandler
{
    public function __construct(
        private Database $database,
        private Mailer $mailer,
        private RetentionPolicy $retention,
        private Translator $translator,
        private Clock $clock,
        private string $appUrl = 'https://example.tld',
    ) {}

    public function type(): string
    {
        return 'listing.expiry_notice';
    }

    public function handle(Job $job): string
    {
        $verschickt = 0;

        foreach ($this->retention->reminderDays() as $tage) {
            $verschickt += $this->remind($tage);
        }

        return \sprintf('%d Erinnerungen verschickt', $verschickt);
    }

    private function remind(int $tage): int
    {
        $now = $this->clock->now();
        $von = $now->modify(\sprintf('+%d days', $tage - 1));
        $bis = $now->modify(\sprintf('+%d days', $tage));
        $marke = \sprintf('ablauf_%d', $tage);

        $anzeigen = $this->database->select(
            <<<'SQL'
                SELECT l.id, l.user_id, l.title, l.expires_at, u.email, u.display_name
                  FROM listings l
                  JOIN users u ON u.id = l.user_id
                 WHERE l.status = 'aktiv'
                   AND l.expires_at > :von AND l.expires_at <= :bis
                   AND u.status = 'aktiv'
                   AND NOT EXISTS (
                       SELECT 1 FROM audit_log a
                        WHERE a.entity_type = 'listing' AND a.entity_id = l.id
                          AND a.action = :marke
                   )
                 LIMIT 500
                SQL,
            ['von' => Timestamp::utc($von), 'bis' => Timestamp::utc($bis), 'marke' => 'listing.' . $marke],
        );

        $verschickt = 0;

        foreach ($anzeigen as $anzeige) {
            $platzhalter = [
                'name' => (string) $anzeige['display_name'],
                'anzeige' => (string) $anzeige['title'],
                'tage' => $tage,
                'link' => $this->appUrl . '/meine-anzeigen/',
            ];

            $this->mailer->send(new MailMessage(
                (string) $anzeige['email'],
                $this->translator->choose('mail.ablauf.betreff', $tage, $platzhalter),
                $this->translator->choose('mail.ablauf.text', $tage, $platzhalter),
                (string) $anzeige['display_name'],
                NotificationChannel::AnzeigeAblauf->value,
                (int) $anzeige['user_id'],
            ));

            // Der Vermerk ist die Idempotenzmarke — deshalb direkt hier und
            // nicht ueber den AuditLog-Dienst, der eine Aktorrolle verlangt.
            $this->database->execute(
                "INSERT INTO audit_log (occurred_at, actor_type, action, entity_type, entity_id, data_json)
                 VALUES (:now, 'system', :action, 'listing', :id, '{}')",
                [
                    'now' => Timestamp::utc($this->clock->now()),
                    'action' => 'listing.' . $marke,
                    'id' => (int) $anzeige['id'],
                ],
            );

            ++$verschickt;
        }

        return $verschickt;
    }
}
