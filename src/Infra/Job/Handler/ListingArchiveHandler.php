<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Job\Handler;

use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobHandler;
use Reptilienmarkt\Domain\Listing\ListingStatus;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Search\ListingIndexer;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Timestamp;

/**
 * Setzt abgelaufene Anzeigen auf "abgelaufen" und nimmt sie aus der Suche.
 *
 * Zwei Schritte, die zusammengehoeren: Eine abgelaufene Anzeige, die noch im
 * Volltextindex steht, taucht in Treffern auf und fuehrt ins Leere.
 */
final readonly class ListingArchiveHandler implements JobHandler
{
    public function __construct(
        private Database $database,
        private ListingIndexer $indexer,
        private Clock $clock,
    ) {}

    public function type(): string
    {
        return 'listing.archive';
    }

    public function handle(Job $job): string
    {
        $now = Timestamp::utc($this->clock->now());

        $faellig = $this->database->select(
            // Pausierte gehoeren dazu: Die Laufzeit laeuft waehrend der Pause
            // weiter — so steht es auch im Formular. Waeren sie ausgenommen,
            // liesse sich eine Anzeige durch Pausieren unbegrenzt am Leben
            // halten.
            "SELECT id FROM listings
              WHERE status IN ('aktiv','reserviert','pausiert')
                AND expires_at IS NOT NULL AND expires_at <= :now
              LIMIT 500",
            ['now' => $now],
        );

        foreach ($faellig as $zeile) {
            $id = (int) $zeile['id'];

            $this->database->execute(
                'UPDATE listings SET status = :status, updated_at = :now WHERE id = :id',
                ['status' => ListingStatus::Abgelaufen->value, 'now' => $now, 'id' => $id],
            );

            $this->indexer->removeListing($id);
        }

        return \sprintf('%d Anzeigen archiviert', \count($faellig));
    }
}
