<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Job\Handler;

use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobHandler;
use Reptilienmarkt\Domain\Mail\Mailer;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Timestamp;
use Reptilienmarkt\Support\Translator;

/**
 * Benachrichtigt ueber neue Treffer zu gespeicherten Suchen.
 *
 * Der Fortschritt haengt an last_seen_listing_id, nicht an einem Zeitstempel:
 * Kennungen sind lueckenlos aufsteigend, Zeitstempel koennen bei
 * Sommerzeitwechsel oder ungenauer Uhr springen. Mit der Kennung kann keine
 * Anzeige uebersprungen und keine doppelt gemeldet werden.
 */
final readonly class SavedSearchAlertHandler implements JobHandler
{
    private const int MAX_HITS_PER_MAIL = 10;

    public function __construct(
        private Database $database,
        private Mailer $mailer,
        private Translator $translator,
        private Clock $clock,
        private string $appUrl = 'https://example.tld',
    ) {}

    public function type(): string
    {
        return 'saved_search.alert';
    }

    public function handle(Job $job): string
    {
        $frequenz = $job->string('frequenz', 'taeglich');
        $now = $this->clock->now();

        $suchen = $this->database->select(
            <<<'SQL'
                SELECT s.id, s.user_id, s.name, s.filter_json, s.last_seen_listing_id,
                       u.email, u.display_name
                  FROM saved_searches s
                  JOIN users u ON u.id = s.user_id
                 WHERE s.alert_frequency = :frequenz AND u.status = 'aktiv'
                 LIMIT 500
                SQL,
            ['frequenz' => $frequenz],
        );

        $benachrichtigt = 0;

        foreach ($suchen as $suche) {
            $seit = (int) ($suche['last_seen_listing_id'] ?? 0);

            // Bewusst schlicht: die neuen aktiven Anzeigen seit dem letzten
            // Lauf. Die Filter der gespeicherten Suche vollstaendig
            // nachzubilden gehoert in den Suchdienst, nicht in einen Job —
            // hier steht die Zustellung, nicht die Suchlogik.
            $treffer = $this->database->select(
                "SELECT id, title FROM listings
                  WHERE status = 'aktiv' AND id > :seit
                  ORDER BY id DESC LIMIT :limit",
                ['seit' => $seit, 'limit' => self::MAX_HITS_PER_MAIL],
            );

            if ($treffer === []) {
                continue;
            }

            $zeilen = array_map(
                fn(array $zeile): string => \sprintf(
                    '- %s: %s/anzeige/%d/',
                    (string) $zeile['title'],
                    $this->appUrl,
                    (int) $zeile['id'],
                ),
                $treffer,
            );

            $platzhalter = [
                'name' => (string) $suche['display_name'],
                'suche' => (string) $suche['name'],
                'treffer' => implode("\n", $zeilen),
                'link' => $this->appUrl . '/konto/',
            ];

            $this->mailer->send(new MailMessage(
                (string) $suche['email'],
                $this->translator->translate('mail.suchtreffer.betreff', $platzhalter),
                $this->translator->translate('mail.suchtreffer.text', $platzhalter),
                (string) $suche['display_name'],
            ));

            $this->database->execute(
                'UPDATE saved_searches SET last_seen_listing_id = :id, last_alert_at = :now, updated_at = :now
                  WHERE id = :suche',
                [
                    'id' => (int) $treffer[0]['id'],
                    'now' => Timestamp::utc($now),
                    'suche' => (int) $suche['id'],
                ],
            );

            ++$benachrichtigt;
        }

        return \sprintf('%d Benachrichtigungen (%s)', $benachrichtigt, $frequenz);
    }
}
