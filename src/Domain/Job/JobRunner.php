<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Job;

use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Log\Logger;
use Throwable;

/**
 * Arbeitet die Auftragstabelle ab.
 *
 * Grundsatz: Ein fehlgeschlagener Auftrag darf den Worker nicht anhalten. Jede
 * Ausnahme wird gefangen, protokolliert und fuehrt zu einem erneuten Versuch
 * mit wachsendem Abstand — bis die Versuche aufgebraucht sind. Erst dann gilt
 * der Auftrag als gescheitert und wartet auf einen Menschen.
 */
final readonly class JobRunner
{
    /**
     * Wartezeit vor dem naechsten Versuch, in Sekunden. Wachsend, damit ein
     * Auftrag, der an einem laenger gestoerten Dienst haengt, nicht im
     * Sekundentakt dagegenlaeuft.
     */
    private const array BACKOFF_SECONDS = [60, 300, 900, 3600];

    /**
     * Nach dieser Zeit gilt ein laufender Auftrag als verwaist — der Worker
     * ist vermutlich abgestuerzt.
     */
    public const int STALE_MINUTES = 30;

    /**
     * @param array<string, JobHandler> $handlers Typ => Handler
     */
    public function __construct(
        private JobRepository $jobs,
        private array $handlers,
        private Clock $clock,
        private Logger $logger,
        private string $workerId = 'worker',
    ) {}

    /**
     * Arbeitet bis zu $max Auftraege ab.
     *
     * @return array{erledigt: int, fehlgeschlagen: int, wiederholt: int}
     */
    public function run(int $max = 50, string $queue = 'default'): array
    {
        $bilanz = ['erledigt' => 0, 'fehlgeschlagen' => 0, 'wiederholt' => 0];

        for ($i = 0; $i < $max; ++$i) {
            $job = $this->jobs->reserve($this->workerId, $this->clock->now(), $queue);

            if ($job === null) {
                break;
            }

            $ergebnis = $this->runOne($job);
            ++$bilanz[$ergebnis];
        }

        return $bilanz;
    }

    /**
     * Gibt Auftraege frei, deren Worker verschwunden ist.
     */
    public function releaseStale(): int
    {
        $now = $this->clock->now();

        return $this->jobs->releaseStale($now->modify(\sprintf('-%d minutes', self::STALE_MINUTES)), $now);
    }

    /**
     * @return 'erledigt'|'fehlgeschlagen'|'wiederholt'
     */
    private function runOne(Job $job): string
    {
        $handler = $this->handlers[$job->type] ?? null;
        $id = $job->id ?? 0;

        if ($handler === null) {
            // Ein unbekannter Typ wird nicht wiederholt: Der naechste Versuch
            // waere genauso unbekannt.
            $this->jobs->fail($id, \sprintf('Kein Handler fuer "%s".', $job->type), $this->clock->now());
            $this->logger->error('job.no_handler', ['job_id' => $id, 'typ' => $job->type]);

            return 'fehlgeschlagen';
        }

        $start = microtime(true);

        try {
            $zusammenfassung = $handler->handle($job);

            $this->jobs->complete($id, $this->clock->now());
            $this->logger->info('job.completed', [
                'job_id' => $id,
                'typ' => $job->type,
                'dauer_ms' => round((microtime(true) - $start) * 1000, 1),
                'ergebnis' => $zusammenfassung,
            ]);

            return 'erledigt';
        } catch (Throwable $exception) {
            return $this->handleFailure($job, $exception);
        }
    }

    /**
     * @return 'fehlgeschlagen'|'wiederholt'
     */
    private function handleFailure(Job $job, Throwable $exception): string
    {
        $id = $job->id ?? 0;
        $meldung = \sprintf('%s: %s', $exception::class, $exception->getMessage());

        // attempts wurde beim Reservieren hochgezaehlt.
        if ($job->attempts >= $job->maxAttempts) {
            $this->jobs->fail($id, $meldung, $this->clock->now());
            $this->logger->error('job.failed', [
                'job_id' => $id,
                'typ' => $job->type,
                'versuche' => $job->attempts,
                'fehler' => $meldung,
                'datei' => $exception->getFile() . ':' . $exception->getLine(),
            ]);

            return 'fehlgeschlagen';
        }

        $wartezeit = self::BACKOFF_SECONDS[min($job->attempts - 1, \count(self::BACKOFF_SECONDS) - 1)] ?? 3600;
        $this->jobs->release($id, $this->clock->now()->modify(\sprintf('+%d seconds', $wartezeit)), $meldung);

        $this->logger->warning('job.retry', [
            'job_id' => $id,
            'typ' => $job->type,
            'versuch' => $job->attempts,
            'naechster_in_s' => $wartezeit,
            'fehler' => $meldung,
        ]);

        return 'wiederholt';
    }

    /**
     * @return list<string>
     */
    public function knownTypes(): array
    {
        return array_keys($this->handlers);
    }
}
