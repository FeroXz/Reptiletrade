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

    /**
     * Die Breiten, in denen jedes Anzeigenbild vorliegt.
     *
     * Drei reichen: 400 fuer die Trefferkachel auf dem Telefon, 800 fuer die
     * Kachel auf dem Schreibtisch und die Detailseite auf dem Telefon, 1600 fuer
     * die Detailseite gross. Mehr Stufen bringen bei WebP kaum noch etwas ein
     * und kosten bei jedem Upload Rechenzeit.
     *
     * Die groesste Fassung ist die Datei selbst — so bleibt der Pfad, der in
     * listing_media steht, der, den es schon immer gab.
     */
    public const array WIDTHS = [400, 800, 1600];

    /**
     * Der Pfad einer Breite. Die groesste Breite ist die Datei selbst.
     */
    public static function variantFor(string $relativePath, int $width): string
    {
        if ($width >= max(self::WIDTHS)) {
            return $relativePath;
        }

        return preg_replace('/\.webp$/', '-' . $width . '.webp', $relativePath) ?? $relativePath;
    }

    /**
     * Breite => Pfad, fuer srcset.
     *
     * @return array<int, string>
     */
    public static function variantsFor(string $relativePath): array
    {
        $pfade = [];

        foreach (self::WIDTHS as $breite) {
            $pfade[$breite] = self::variantFor($relativePath, $breite);
        }

        return $pfade;
    }

    /**
     * Das srcset-Attribut zu einem Bild.
     *
     * Nur die Fassungen, die es tatsaechlich gibt: Bestandsbilder haben ihre
     * kleinen Groessen erst, wenn bin/reimage.php gelaufen ist, und ein
     * Verweis auf eine fehlende Datei waere ein 404 in jeder Trefferliste.
     */
    public function srcset(string $relativePath): string
    {
        $teile = [];

        foreach (self::variantsFor($relativePath) as $breite => $pfad) {
            if (is_file($this->basePath . '/' . $pfad)) {
                $teile[] = '/uploads/' . $pfad . ' ' . $breite . 'w';
            }
        }

        return implode(', ', $teile);
    }

    /**
     * Zielpfade aller Breiten fuer die ImagePipeline.
     *
     * @return array<int, string> Kantenlaenge => absoluter Pfad
     */
    public function variantTargets(string $relativePath): array
    {
        $ziele = [];

        foreach (self::variantsFor($relativePath) as $breite => $pfad) {
            $ziele[$breite] = $this->basePath . '/' . $pfad;
        }

        return $ziele;
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->basePath . '/' . $relativePath;
    }

    public function delete(string $relativePath): void
    {
        $kandidaten = array_merge(
            [$relativePath, self::thumbnailFor($relativePath)],
            array_values(self::variantsFor($relativePath)),
        );

        foreach ($kandidaten as $candidate) {
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
