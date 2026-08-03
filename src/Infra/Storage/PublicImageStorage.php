<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Storage;

/**
 * Ablage der Anzeigenbilder unter public/uploads. Die Dateien sind oeffentlich
 * abrufbar — deshalb landen hier ausschliesslich die von der ImagePipeline neu
 * gezeichneten WebP-Dateien ohne Metadaten, niemals das Original.
 */
final readonly class PublicImageStorage
{
    public function __construct(private string $basePath) {}

    /**
     * Erzeugt Zielpfade fuer Bild und Miniatur. Die Miniatur folgt der
     * Namenskonvention "-thumb", damit kein zweites Feld gepflegt werden muss.
     *
     * @return array{relative: string, absolute: string, thumbRelative: string, thumbAbsolute: string}
     */
    public function allocate(int $listingId): array
    {
        $relative = \sprintf('anzeigen/%d/%s.webp', $listingId, bin2hex(random_bytes(12)));
        $thumbRelative = self::thumbnailFor($relative);

        return [
            'relative' => $relative,
            'absolute' => $this->basePath . '/' . $relative,
            'thumbRelative' => $thumbRelative,
            'thumbAbsolute' => $this->basePath . '/' . $thumbRelative,
        ];
    }

    public static function thumbnailFor(string $relativePath): string
    {
        return preg_replace('/\.webp$/', '-thumb.webp', $relativePath) ?? $relativePath;
    }

    public function delete(string $relativePath): void
    {
        foreach ([$relativePath, self::thumbnailFor($relativePath)] as $candidate) {
            if (str_contains($candidate, '..')) {
                continue;
            }

            $absolute = $this->basePath . '/' . $candidate;
            if (is_file($absolute)) {
                unlink($absolute);
            }
        }
    }
}
