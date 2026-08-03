<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support;

/**
 * Erzeugt URL-Segmente. Deutsche Umlaute werden ausgeschrieben, damit
 * /markt/griechische-landschildkroete/ lesbar bleibt.
 */
final class Slugger
{
    private const array TRANSLITERATION = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y',
        'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o',
    ];

    public static function slug(string $value): string
    {
        $value = strtr($value, self::TRANSLITERATION);
        $value = mb_strtolower($value, 'UTF-8');
        $value = (string) preg_replace('/[^a-z0-9]+/u', '-', $value);

        return trim($value, '-');
    }

    /**
     * Mehrere Bezeichner zu einem Segment, z. B. Morphs zu "red-hypo-translucent".
     *
     * @param list<string> $values
     */
    public static function combine(array $values): string
    {
        $parts = [];
        foreach ($values as $value) {
            $slug = self::slug($value);
            if ($slug !== '') {
                $parts[] = $slug;
            }
        }

        return implode('-', $parts);
    }
}
