<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Job\Handler;

use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobHandler;
use Reptilienmarkt\Domain\Privacy\RetentionPolicy;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Storage\PrivateStorage;
use Reptilienmarkt\Support\Log\Logger;
use Reptilienmarkt\Support\Timestamp;

/**
 * Setzt die Aufbewahrungsfristen um.
 *
 * Jede Datenart hat ihre eigene Frist, weil die Gruende verschieden sind —
 * siehe config/aufbewahrung.php. Eine Frist von 0 schaltet die jeweilige
 * Loeschung ab; das ist eine Betreiberentscheidung und wird respektiert.
 *
 * Der Audit-Trail wird ausdruecklich **nicht** angefasst. Er ist per Trigger
 * append-only und dokumentiert Rechtsentscheidungen und Moderationsvorgaenge;
 * ihn zu beschneiden waere der eine Fall, in dem Aufraeumen schadet.
 */
final readonly class RetentionHandler implements JobHandler
{
    public function __construct(
        private Database $database,
        private RetentionPolicy $retention,
        private PrivateStorage $privateStorage,
        private Logger $logger,
    ) {}

    public function type(): string
    {
        return 'retention.enforce';
    }

    public function handle(Job $job): string
    {
        $bilanz = [];

        $bilanz['nachrichten'] = $this->purgeConversations();
        $bilanz['identitaetsnachweise'] = $this->purgeUserDocuments();
        $bilanz['rechtsnachweise'] = $this->purgeLegalDocuments();
        $bilanz['anzeigen'] = $this->purgeListings();
        $bilanz['jobs'] = $this->purgeSimple('jobs_tage', "DELETE FROM jobs WHERE status = 'erledigt' AND completed_at < :vor");
        $bilanz['rate_limits'] = $this->purgeSimple('rate_limits_tage', 'DELETE FROM rate_limit_hits WHERE occurred_at < :vor');
        // "zugangstoken", nicht "token": Der Logger schwaerzt jedes Feld, das
        // wortgleich "token" heisst — hier waere die Zahl damit unlesbar.
        $bilanz['zugangstoken'] = $this->purgeSimple('token_tage', 'DELETE FROM user_tokens WHERE expires_at < :vor');
        $bilanz['sitzungen'] = $this->purgeSimple('sitzungen_tage', 'DELETE FROM sessions WHERE expires_at < :vor');
        // Nur erledigte Zeilen: "wartend" heisst, dass die Mail noch nicht raus
        // ist — sie wegzuraeumen hiesse, sie stillschweigend zu verlieren, und
        // zwar genau die, auf die jemand wartet. Gerechnet wird ab updated_at,
        // denn das ist bei beiden Endzustaenden der Zeitpunkt, an dem die Zeile
        // fertig wurde; sent_at gibt es nur bei den zugestellten.
        $bilanz['postausgang'] = $this->purgeSimple(
            'postausgang_tage',
            "DELETE FROM mail_outbox WHERE status IN ('gesendet', 'fehlgeschlagen') AND updated_at < :vor",
        );

        $this->logger->info('retention.enforced', $bilanz);

        $teile = [];
        foreach ($bilanz as $bereich => $anzahl) {
            if ($anzahl > 0) {
                $teile[] = $bereich . ': ' . $anzahl;
            }
        }

        return $teile === [] ? 'nichts zu löschen' : implode(', ', $teile);
    }

    /**
     * Gespraeche ohne Bewegung. Geloescht wird das Gespraech, die Nachrichten
     * gehen per Fremdschluessel mit.
     */
    private function purgeConversations(): int
    {
        $stichtag = $this->retention->cutoff('nachrichten_tage');

        if ($stichtag === null) {
            return 0;
        }

        return $this->database->execute(
            'DELETE FROM conversations WHERE COALESCE(last_message_at, created_at) < :vor',
            ['vor' => Timestamp::utc($stichtag)],
        );
    }

    /**
     * Ausweis- und Gewerbenachweise. Kurze Frist: Nach der Pruefung ist ihr
     * Zweck erfuellt.
     */
    private function purgeUserDocuments(): int
    {
        $stichtag = $this->retention->cutoff('identitaetsnachweise_tage');

        if ($stichtag === null) {
            return 0;
        }

        $faellig = $this->database->select(
            "SELECT id, private_path FROM user_documents
              WHERE status <> 'offen' AND reviewed_at IS NOT NULL AND reviewed_at < :vor
              LIMIT 500",
            ['vor' => Timestamp::utc($stichtag)],
        );

        foreach ($faellig as $nachweis) {
            // Erst die Datei, dann die Zeile: Andersherum bliebe eine Datei
            // ohne Zeile liegen, und niemand wuesste mehr, wozu sie gehoert.
            $this->privateStorage->delete((string) $nachweis['private_path']);
            $this->database->execute('DELETE FROM user_documents WHERE id = :id', ['id' => (int) $nachweis['id']]);
        }

        return \count($faellig);
    }

    /**
     * Rechtsnachweise am Listing. Lange Frist, weil sie im Streitfall die
     * Rechtmaessigkeit der Abgabe belegen. Geloescht wird die Datei; die
     * Referenznummer bleibt als Nachweis stehen, dass es sie gab.
     */
    private function purgeLegalDocuments(): int
    {
        $stichtag = $this->retention->cutoff('rechtsnachweise_tage');

        if ($stichtag === null) {
            return 0;
        }

        $faellig = $this->database->select(
            'SELECT id, private_path FROM legal_docs
              WHERE private_path IS NOT NULL AND created_at < :vor
              LIMIT 500',
            ['vor' => Timestamp::utc($stichtag)],
        );

        foreach ($faellig as $nachweis) {
            $this->privateStorage->delete((string) $nachweis['private_path']);
            $this->database->execute(
                'UPDATE legal_docs SET private_path = NULL, original_filename = NULL, mime_type = NULL,
                                       byte_size = NULL
                  WHERE id = :id',
                ['id' => (int) $nachweis['id']],
            );
        }

        return \count($faellig);
    }

    private function purgeListings(): int
    {
        $stichtag = $this->retention->cutoff('anzeigen_tage');

        if ($stichtag === null) {
            return 0;
        }

        // Nur beendete Anzeigen, und nur solche ohne Bewertung: Eine Bewertung
        // verweist auf die Anzeige, und ohne sie stuende sie im Nichts.
        return $this->database->execute(
            <<<'SQL'
                DELETE FROM listings
                 WHERE status IN ('abgelaufen','verkauft')
                   AND updated_at < :vor
                   AND NOT EXISTS (SELECT 1 FROM reviews r WHERE r.listing_id = listings.id)
                SQL,
            ['vor' => Timestamp::utc($stichtag)],
        );
    }

    private function purgeSimple(string $key, string $sql): int
    {
        $stichtag = $this->retention->cutoff($key);

        return $stichtag === null ? 0 : $this->database->execute($sql, ['vor' => Timestamp::utc($stichtag)]);
    }
}
