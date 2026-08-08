<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Storage;

use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Domain\Content\ContentBlock;
use Reptilienmarkt\Domain\Content\ContentException;
use Reptilienmarkt\Domain\Content\Media;
use Reptilienmarkt\Domain\Content\MediaRepository;
use Reptilienmarkt\Domain\Content\MediaUsageContext;
use Reptilienmarkt\Domain\Content\MediaUsageRepository;
use Reptilienmarkt\Support\Clock;

/**
 * Hochladen, Beschriften und Loeschen von Redaktionsbildern.
 *
 * Dieselbe Verarbeitung wie bei den Anzeigenbildern: dekodieren,
 * EXIF-Ausrichtung einrechnen, auf frische Leinwand kopieren, als WebP
 * schreiben. Damit ueberlebt kein Metadatenblock — es gibt schlicht nichts zu
 * uebertragen.
 *
 * Die Klasse liegt in src/Infra und nicht in der Domain: Sie haengt an GD und
 * am Dateisystem. Sie kennt die Domain — Entitaeten und Repository-Interfaces
 * —, nicht umgekehrt. Dieselbe Richtung wie bei den Anzeigenbildern.
 */
final readonly class MediaService
{
    public function __construct(
        private MediaRepository $media,
        private MediaUsageRepository $usages,
        private ImagePipeline $pipeline,
        private MediaStorage $storage,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    /**
     * Nimmt eine hochgeladene Datei an.
     *
     * Dedupliziert ueber den Hash des **verarbeiteten** Bilds, nicht den der
     * hochgeladenen Datei: Zwei JPEGs desselben Motivs mit unterschiedlicher
     * Kompression ergeben dasselbe WebP, und genau das soll einmal in der
     * Mediathek stehen.
     *
     * @throws ContentException
     */
    public function upload(string $temporaryPath, string $originalFilename, ?int $uploadedBy): Media
    {
        $moment = $this->clock->now();
        $ziel = $this->storage->allocate($moment);

        try {
            $varianten = $this->pipeline->processVariants($temporaryPath, $ziel['targets']);
        } catch (ImageException $exception) {
            throw new ContentException($exception->getMessage(), 0, $exception);
        }

        $gross = $varianten[Media::WIDTHS[array_key_last(Media::WIDTHS)]] ?? null;

        if ($gross === null) {
            throw new ContentException('Das Bild konnte nicht verarbeitet werden.');
        }

        $hash = hash_file('sha256', $gross->path);

        if ($hash === false) {
            throw new ContentException('Das verarbeitete Bild konnte nicht gelesen werden.');
        }

        $bekannt = $this->media->findByHash($hash);

        if ($bekannt !== null) {
            // Schon da: Die eben geschriebenen Dateien wieder weg, damit keine
            // verwaisten Kopien liegenbleiben.
            $this->storage->delete($ziel['relative']);

            return $bekannt;
        }

        $media = new Media(
            id: null,
            sha256: $hash,
            path: $ziel['relative'],
            width: $gross->width,
            height: $gross->height,
            byteSize: $gross->byteSize,
            originalFilename: self::cleanFilename($originalFilename),
            uploadedBy: $uploadedBy,
            createdAt: $moment,
        );

        $id = $this->media->save($media);

        $this->audit->record(new AuditEntry('media.created', 'media', $id, [
            'pfad' => $media->path,
            'name' => $media->originalFilename,
        ], $uploadedBy));

        return new Media(
            id: $id,
            sha256: $media->sha256,
            path: $media->path,
            width: $media->width,
            height: $media->height,
            byteSize: $media->byteSize,
            originalFilename: $media->originalFilename,
            uploadedBy: $uploadedBy,
            createdAt: $moment,
        );
    }

    public function describe(int $id, string $altText, string $caption): void
    {
        $this->media->updateText($id, trim($altText), trim($caption));
    }

    /**
     * Loescht ein Bild — nur, wenn es nirgends mehr steht.
     *
     * Die Verwendungen zaehlen, nicht das Bauchgefuehl des Loeschenden: Wer ein
     * Bild von drei Seiten wegnimmt, sieht diese drei Seiten nicht.
     *
     * @throws ContentException
     */
    public function delete(int $id, ?int $actorId, bool $confirmed = false): void
    {
        $media = $this->media->findById($id);

        if ($media === null) {
            throw new ContentException('Dieses Bild gibt es nicht (mehr).');
        }

        $verwendungen = $this->usages->forMedia($id);

        if ($verwendungen !== []) {
            throw new ContentException(\sprintf(
                'Dieses Bild steht noch an %d Stelle(n): %s. Nimm es dort zuerst heraus.',
                \count($verwendungen),
                implode(', ', array_map(
                    static fn(array $u): string => $u['titel'] !== '' ? $u['titel'] : ('#' . $u['context_id']),
                    $verwendungen,
                )),
            ));
        }

        if (!$confirmed) {
            throw new ContentException('Zum Löschen fehlt die Bestätigung.');
        }

        // Erst die Zeile, dann die Dateien: Bleibt eine Datei liegen, raeumt
        // media.cleanup sie ab. Bliebe umgekehrt die Zeile liegen, zeigte die
        // Mediathek ein Bild, das es nicht mehr gibt.
        $this->media->delete($id);
        $this->storage->delete($media->path);

        $this->audit->record(new AuditEntry('media.deleted', 'media', $id, [
            'pfad' => $media->path,
            'name' => $media->originalFilename,
        ], $actorId));
    }

    /**
     * Schreibt die Verwendungen eines Eintrags neu — aus seinen Bloecken.
     *
     * @param list<ContentBlock> $blocks
     */
    public function syncUsages(int $entryId, array $blocks, ?int $ogImageId = null): void
    {
        $ids = [];

        foreach ($blocks as $block) {
            foreach ($block->mediaIds() as $mediaId) {
                $ids[] = $mediaId;
            }
        }

        $this->usages->replaceFor(MediaUsageContext::Block, $entryId, $ids);
        $this->usages->replaceFor(
            MediaUsageContext::EntryOg,
            $entryId,
            $ogImageId === null || $ogImageId <= 0 ? [] : [$ogImageId],
        );
    }

    /**
     * @return list<array{context: MediaUsageContext, context_id: int, titel: string, pfad: string}>
     */
    public function usagesOf(int $mediaId): array
    {
        return $this->usages->forMedia($mediaId);
    }

    /**
     * Der Dateiname dient nur dem Wiedererkennen in der Mediathek. Er wandert
     * nie in einen Pfad — der entsteht aus Zufallsbytes — und wird deshalb nur
     * auf eine lesbare Laenge gebracht.
     */
    private static function cleanFilename(string $filename): string
    {
        $name = trim(basename($filename));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        return mb_substr($name, 0, 120, 'UTF-8');
    }
}
