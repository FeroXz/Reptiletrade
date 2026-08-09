<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Job\Handler;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobHandler;
use Reptilienmarkt\Infra\Persistence\Database;
use Reptilienmarkt\Infra\Storage\PublicImageStorage;
use Reptilienmarkt\Support\Log\Logger;
use SplFileInfo;

/**
 * Raeumt verwaiste Bilddateien auf.
 *
 * Verwaist heisst: Die Datei liegt auf der Platte, aber keine Zeile in
 * listing_media zeigt darauf. Das passiert, wenn ein Upload zwischen
 * Dateisystem und Datenbank abbricht.
 *
 * Die Richtung ist bewusst nur diese eine. Der umgekehrte Fall — Zeile ohne
 * Datei — wird protokolliert, aber nicht geloescht: Eine fehlende Datei kann
 * ein Einhaengeproblem sein, und dann waere das Loeschen der Zeile ein
 * Datenverlust aus einem voruebergehenden Fehler.
 */
final readonly class MediaCleanupHandler implements JobHandler
{
    /**
     * Neue Dateien bleiben unangetastet: Ein Upload, der gerade laeuft, hat
     * seine Datenbankzeile noch nicht.
     */
    private const int MIN_AGE_SECONDS = 3600;

    public function __construct(
        private Database $database,
        private string $publicPath,
        private Logger $logger,
    ) {}

    public function type(): string
    {
        return 'media.cleanup';
    }

    public function handle(Job $job): string
    {
        $bekannt = [];

        foreach ($this->database->select("SELECT path FROM listing_media WHERE media_type = 'bild'") as $zeile) {
            $pfad = (string) $zeile['path'];
            $bekannt[$pfad] = true;
            $bekannt[PublicImageStorage::thumbnailFor($pfad)] = true;

            // Die kleineren Fassungen stehen in keiner Zeile — sie haengen am
            // Pfad. Ohne diese Schleife hielte die Aufraeumung sie fuer
            // verwaist und loeschte bei jedem Lauf das halbe srcset.
            foreach (PublicImageStorage::variantsFor($pfad) as $variante) {
                $bekannt[$variante] = true;
            }
        }

        $verzeichnis = $this->publicPath . '/anzeigen';

        if (!is_dir($verzeichnis)) {
            return 'Kein Bildverzeichnis vorhanden';
        }

        $geloescht = 0;
        $grenze = time() - self::MIN_AGE_SECONDS;

        $eintraege = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($verzeichnis, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($eintraege as $datei) {
            if (!$datei instanceof SplFileInfo || !$datei->isFile()) {
                continue;
            }

            $relativ = ltrim(str_replace($this->publicPath, '', $datei->getPathname()), '/');

            if (isset($bekannt[$relativ]) || $datei->getMTime() > $grenze) {
                continue;
            }

            @unlink($datei->getPathname());
            ++$geloescht;
        }

        $fehlend = $this->database->select(
            "SELECT id, path FROM listing_media WHERE media_type = 'bild' LIMIT 5000",
        );

        $ohneDatei = 0;
        foreach ($fehlend as $zeile) {
            if (!is_file($this->publicPath . '/' . (string) $zeile['path'])) {
                ++$ohneDatei;
            }
        }

        if ($ohneDatei > 0) {
            // Nur melden, nicht loeschen — siehe Klassenkommentar.
            $this->logger->warning('media.missing_files', ['anzahl' => $ohneDatei]);
        }

        return \sprintf('%d verwaiste Dateien gelöscht, %d Einträge ohne Datei', $geloescht, $ohneDatei);
    }
}
