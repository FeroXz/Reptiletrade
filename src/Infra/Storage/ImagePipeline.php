<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Storage;

use GdImage;
use RuntimeException;

/**
 * Verarbeitet hochgeladene Bilder.
 *
 * Der Ablauf ist bewusst "neu zeichnen statt bearbeiten": Das Bild wird
 * dekodiert und in eine frische Leinwand kopiert. Damit ueberlebt kein
 * einziger Metadatenblock — weder EXIF mit GPS-Koordinaten noch IPTC oder XMP.
 * Ein nachtraegliches Loeschen einzelner Felder waere fehleranfaellig; hier
 * gibt es schlicht nichts zu uebertragen.
 *
 * Die Ausrichtung aus EXIF wird vorher ausgewertet und ins Bild gerechnet,
 * damit hochkant aufgenommene Fotos nicht liegend erscheinen.
 */
final readonly class ImagePipeline
{
    public const int MAX_EDGE = 1600;

    public const int THUMB_EDGE = 400;

    public const int MAX_BYTES = 12 * 1024 * 1024;

    /** @var list<string> */
    private const array ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private int $quality = 82,
        private int $thumbQuality = 74,
    ) {}

    /**
     * @throws ImageException
     */
    public function process(string $sourcePath, string $targetPath, string $thumbnailPath): ProcessedImage
    {
        $this->guardFile($sourcePath);

        $image = $this->decode($sourcePath);

        try {
            $image = $this->applyExifOrientation($image, $sourcePath);
            $full = $this->resize($image, self::MAX_EDGE);

            try {
                $this->writeWebp($full, $targetPath, $this->quality);
                $width = imagesx($full);
                $height = imagesy($full);

                $thumb = $this->resize($full, self::THUMB_EDGE);

                try {
                    $this->writeWebp($thumb, $thumbnailPath, $this->thumbQuality);
                } finally {
                    if ($thumb !== $full) {
                        imagedestroy($thumb);
                    }
                }
            } finally {
                if ($full !== $image) {
                    imagedestroy($full);
                }
            }
        } finally {
            imagedestroy($image);
        }

        $size = filesize($targetPath);

        return new ProcessedImage(
            $targetPath,
            $thumbnailPath,
            $width,
            $height,
            $size === false ? 0 : $size,
        );
    }

    /**
     * Verarbeitet dasselbe Quellbild in mehrere Kantenlaengen.
     *
     * Gebraucht von der Mediathek fuer srcset (400/800/1600). Dieselbe
     * Verarbeitung wie process(): dekodieren, EXIF-Ausrichtung einrechnen, auf
     * frische Leinwand kopieren, als WebP schreiben. Damit ueberlebt auch hier
     * kein Metadatenblock — es gibt schlicht nichts zu uebertragen.
     *
     * Die Groessen werden absteigend abgearbeitet und jeweils aus der
     * vorherigen, groesseren Leinwand gezogen: Das ist schneller als jedes Mal
     * aus dem Original und bei diesen Faktoren nicht sichtbar schlechter.
     *
     * @param array<int, string> $targets maximale Kantenlaenge => Zielpfad
     *
     * @return array<int, ProcessedImage> Kantenlaenge => Ergebnis
     *
     * @throws ImageException
     */
    public function processVariants(string $sourcePath, array $targets): array
    {
        if ($targets === []) {
            throw new ImageException('Es wurde keine Zielgroesse angegeben.');
        }

        $this->guardFile($sourcePath);

        krsort($targets);

        $image = $this->decode($sourcePath);
        $results = [];

        try {
            $image = $this->applyExifOrientation($image, $sourcePath);
            $current = $image;

            foreach ($targets as $edge => $targetPath) {
                $resized = $this->resize($current, $edge);

                // Die grosse Fassung bekommt die hoehere Qualitaet: Sie wird
                // vergroessert betrachtet, die kleinen sind Vorschauen.
                $this->writeWebp($resized, $targetPath, $edge >= self::MAX_EDGE ? $this->quality : $this->thumbQuality);

                $size = filesize($targetPath);
                $results[$edge] = new ProcessedImage(
                    $targetPath,
                    $targetPath,
                    imagesx($resized),
                    imagesy($resized),
                    $size === false ? 0 : $size,
                );

                if ($current !== $image) {
                    imagedestroy($current);
                }

                $current = $resized;
            }

            if ($current !== $image) {
                imagedestroy($current);
            }
        } finally {
            imagedestroy($image);
        }

        return $results;
    }

    private function guardFile(string $path): void
    {
        if (!is_file($path)) {
            throw new ImageException('Die hochgeladene Datei wurde nicht gefunden.');
        }

        $size = filesize($path);
        if ($size === false || $size === 0) {
            throw new ImageException('Die hochgeladene Datei ist leer.');
        }

        if ($size > self::MAX_BYTES) {
            throw new ImageException(\sprintf('Das Bild ist größer als %d MB.', intdiv(self::MAX_BYTES, 1024 * 1024)));
        }

        // Der vom Browser gemeldete Typ zaehlt nicht — nur der Inhalt.
        $info = @getimagesize($path);
        if ($info === false || !\in_array($info['mime'], self::ALLOWED_MIME, true)) {
            throw new ImageException('Nur JPEG, PNG und WebP werden unterstützt.');
        }
    }

    private function decode(string $path): GdImage
    {
        $image = @imagecreatefromstring((string) file_get_contents($path));

        if ($image === false) {
            throw new ImageException('Das Bild konnte nicht gelesen werden.');
        }

        return $image;
    }

    /**
     * EXIF-Ausrichtung ins Bild rechnen, bevor die Metadaten verlorengehen.
     */
    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        if (!\function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        if (!\is_array($exif) || !isset($exif['Orientation']) || !\is_int($exif['Orientation'])) {
            return $image;
        }

        $rotated = match ($exif['Orientation']) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated === false || $rotated === null) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    /**
     * Zeichnet auf eine frische Leinwand — hier entstehen die metadatenfreien Pixel.
     */
    private function resize(GdImage $image, int $maxEdge): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        $scale = $longest > $maxEdge ? $maxEdge / $longest : 1.0;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($canvas === false) {
            throw new ImageException('Das Bild konnte nicht verarbeitet werden.');
        }

        // Transparenz aus PNG und WebP erhalten.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        if (!imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
            imagedestroy($canvas);

            throw new ImageException('Das Bild konnte nicht skaliert werden.');
        }

        return $canvas;
    }

    private function writeWebp(GdImage $image, string $path, int $quality): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException(\sprintf('Zielverzeichnis konnte nicht angelegt werden: %s', $directory));
        }

        if (!imagewebp($image, $path, $quality)) {
            throw new ImageException('Das Bild konnte nicht als WebP gespeichert werden.');
        }
    }
}
