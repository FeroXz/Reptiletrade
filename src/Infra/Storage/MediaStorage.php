<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Storage;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Content\Media;

/**
 * Ablage der Redaktionsbilder unter public/media/JJJJ/MM/.
 *
 * Nach Jahr und Monat, nicht flach: Ein Verzeichnis mit zehntausend Dateien ist
 * fuer jedes Werkzeug muehsam — fuer rsync, fuer ls, fuer den Blick ins
 * Backup. Der Monat ist grob genug, dass niemand rechnen muss, und fein genug,
 * dass die Verzeichnisse handlich bleiben.
 *
 * Hier landen ausschliesslich die von der ImagePipeline neu gezeichneten
 * WebP-Dateien ohne Metadaten, niemals das Original.
 */
final readonly class MediaStorage
{
    public function __construct(private string $basePath) {}

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Erzeugt die Zielpfade fuer alle Groessen eines neuen Bilds.
     *
     * @return array{relative: string, targets: array<int, string>}
     */
    public function allocate(DateTimeImmutable $moment): array
    {
        $relative = \sprintf(
            '%s/%s/%s.webp',
            $moment->format('Y'),
            $moment->format('m'),
            bin2hex(random_bytes(12)),
        );

        $targets = [];
        foreach (Media::WIDTHS as $edge) {
            $targets[$edge] = $this->absolute(Media::variantPath($relative, $edge));
        }

        return ['relative' => $relative, 'targets' => $targets];
    }

    public function absolute(string $relativePath): string
    {
        return $this->basePath . '/' . $relativePath;
    }

    public function exists(string $relativePath): bool
    {
        return !self::escapes($relativePath) && is_file($this->absolute($relativePath));
    }

    /**
     * Loescht alle Groessen eines Bilds.
     */
    public function delete(string $relativePath): void
    {
        foreach (Media::allPaths($relativePath) as $candidate) {
            if (self::escapes($candidate)) {
                continue;
            }

            $absolute = $this->absolute($candidate);
            if (is_file($absolute)) {
                unlink($absolute);
            }
        }
    }

    /**
     * Ein Pfad, der aus dem Ablageverzeichnis herausfuehrt, wird nicht
     * angefasst — auch dann nicht, wenn er aus der eigenen Datenbank kommt.
     */
    private static function escapes(string $relativePath): bool
    {
        return str_contains($relativePath, '..') || str_starts_with($relativePath, '/');
    }
}
