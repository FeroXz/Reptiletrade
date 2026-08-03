<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Job\Handler;

use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobHandler;
use Reptilienmarkt\Domain\Privacy\RetentionPolicy;

/**
 * Raeumt alte Protokolldateien weg.
 *
 * Betrifft ausschliesslich das Anwendungsprotokoll. Der Audit-Trail liegt in
 * der Datenbank, ist per Trigger append-only und wird nie rotiert — er ist
 * kein Protokoll, sondern ein Nachweis.
 */
final readonly class LogRotationHandler implements JobHandler
{
    public function __construct(
        private string $logDirectory,
        private RetentionPolicy $retention,
    ) {}

    public function type(): string
    {
        return 'log.rotate';
    }

    public function handle(Job $job): string
    {
        $stichtag = $this->retention->cutoff('protokoll_tage');

        if ($stichtag === null || !is_dir($this->logDirectory)) {
            return 'nichts zu tun';
        }

        $grenze = $stichtag->getTimestamp();
        $geloescht = 0;

        foreach (glob($this->logDirectory . '/*.log') ?: [] as $datei) {
            if (is_file($datei) && filemtime($datei) < $grenze) {
                @unlink($datei);
                ++$geloescht;
            }
        }

        // Die laufende Datei wird taeglich benannt, damit ueberhaupt etwas zum
        // Rotieren entsteht — siehe Container-Verdrahtung.
        return \sprintf('%d Protokolldateien gelöscht', $geloescht);
    }
}
