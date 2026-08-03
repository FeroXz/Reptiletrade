<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Support;

use RuntimeException;

/**
 * Baut ein JPEG mit echten GPS-Koordinaten im EXIF-Block.
 *
 * Ohne dieses Fixture waere der Test wertlos: Ein Bild ohne EXIF enthaelt
 * hinterher trivialerweise auch keins. Der APP1-Block wird hier von Hand
 * zusammengesetzt, weil GD beim Schreiben ohnehin keine Metadaten erzeugt.
 */
final class JpegWithGps
{
    /**
     * @return string Pfad zur erzeugten Datei
     */
    public static function create(string $path, float $latitude = 48.137430, float $longitude = 11.575490): string
    {
        $image = imagecreatetruecolor(600, 400);
        if ($image === false) {
            throw new RuntimeException('Testbild konnte nicht erzeugt werden.');
        }

        $farbe = imagecolorallocate($image, 40, 120, 80);
        if ($farbe !== false) {
            imagefilledrectangle($image, 0, 0, 599, 399, $farbe);
        }

        ob_start();
        imagejpeg($image, null, 92);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        $exif = self::exifSegment($latitude, $longitude);

        // Der APP1-Block gehoert direkt hinter den SOI-Marker (0xFFD8).
        $withExif = substr($jpeg, 0, 2) . $exif . substr($jpeg, 2);

        if (file_put_contents($path, $withExif) === false) {
            throw new RuntimeException('Testbild konnte nicht geschrieben werden.');
        }

        return $path;
    }

    private static function exifSegment(float $latitude, float $longitude): string
    {
        // GPS-IFD mit vier Eintraegen; die Rationalwerte stehen dahinter.
        $gpsEntries = 4;
        $gpsIfdOffset = 8 + 2 + 12 + 4;                       // TIFF-Header + IFD0
        $dataOffset = $gpsIfdOffset + 2 + $gpsEntries * 12 + 4;

        $latitudeData = self::degreesMinutesSeconds($latitude);
        $longitudeData = self::degreesMinutesSeconds($longitude);

        $ifd0 = pack('v', 1)                                  // ein Eintrag
            . self::entry(0x8825, 4, 1, $gpsIfdOffset)         // Zeiger auf das GPS-IFD
            . pack('V', 0);                                   // kein weiteres IFD

        $gpsIfd = pack('v', $gpsEntries)
            . self::asciiEntry(0x0001, $latitude >= 0 ? 'N' : 'S')
            . self::entry(0x0002, 5, 3, $dataOffset)
            . self::asciiEntry(0x0003, $longitude >= 0 ? 'E' : 'W')
            . self::entry(0x0004, 5, 3, $dataOffset + 24)
            . pack('V', 0);

        $tiff = 'II' . pack('v', 0x002A) . pack('V', 8) . $ifd0 . $gpsIfd . $latitudeData . $longitudeData;

        $payload = "Exif\0\0" . $tiff;

        return "\xFF\xE1" . pack('n', \strlen($payload) + 2) . $payload;
    }

    private static function entry(int $tag, int $type, int $count, int $value): string
    {
        return pack('v', $tag) . pack('v', $type) . pack('V', $count) . pack('V', $value);
    }

    private static function asciiEntry(int $tag, string $letter): string
    {
        // Zwei Bytes passen direkt in das Wertfeld.
        return pack('v', $tag) . pack('v', 2) . pack('V', 2) . $letter . "\0\0\0";
    }

    private static function degreesMinutesSeconds(float $coordinate): string
    {
        $coordinate = abs($coordinate);
        $degrees = (int) $coordinate;
        $minutesFloat = ($coordinate - $degrees) * 60;
        $minutes = (int) $minutesFloat;
        $seconds = (int) round(($minutesFloat - $minutes) * 60 * 1000);

        return pack('V', $degrees) . pack('V', 1)
            . pack('V', $minutes) . pack('V', 1)
            . pack('V', $seconds) . pack('V', 1000);
    }
}
