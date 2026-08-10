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
     * Die vorhandenen Breiten als Spaltenwert: sortiert, kommagetrennt.
     *
     * @param list<int> $widths
     */
    public static function widthList(array $widths): string
    {
        $vorhanden = array_values(array_filter($widths, static fn(int $breite): bool => \in_array($breite, self::WIDTHS, true)));
        sort($vorhanden);

        return implode(',', $vorhanden);
    }

    /**
     * Das srcset-Attribut zu einem Bild.
     *
     * Die Breiten kommen aus listing_media.variant_widths und werden **nicht**
     * auf der Platte nachgesehen: Die Frage "welche Fassungen gibt es?" aendert
     * ihre Antwort nur beim Upload und beim Nachrechnen, das Beantworten kostete
     * aber je Treffer drei Dateisystemzugriffe.
     *
     * Fehlt der Wert — ein Bestandsbild vor dem Lauf von bin/reimage.php —,
     * bleibt das Ergebnis leer und das img faellt auf sein src zurueck. Ein
     * Verweis auf eine Datei, die es nicht gibt, waere ein 404 in jeder
     * Trefferliste.
     */
    public function srcset(string $relativePath, ?string $variantWidths): string
    {
        if ($variantWidths === null || trim($variantWidths) === '') {
            return '';
        }

        $teile = [];

        foreach (explode(',', $variantWidths) as $wert) {
            $breite = (int) trim($wert);

            // Nur bekannte Breiten: Ein Wert, den WIDTHS nicht kennt, hat
            // keinen Pfad, unter dem eine Datei laege.
            if (!\in_array($breite, self::WIDTHS, true)) {
                continue;
            }

            $teile[] = '/uploads/' . self::variantFor($relativePath, $breite) . ' ' . $breite . 'w';
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
