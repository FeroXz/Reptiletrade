<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Trust;

/**
 * Eine Gruppe verdaechtiger Formulierungen mit gemeinsamer Begruendung.
 */
final readonly class KeywordRule
{
    /**
     * @param list<string> $words kleingeschriebene Suchbegriffe
     */
    public function __construct(
        public string $key,
        public KeywordMode $mode,
        public string $reason,
        public array $words,
    ) {}

    /**
     * Treffer im Text. Gesucht wird an Wortgrenzen, damit "vorkasse" nicht in
     * "Vorkassenregelung" haengenbleibt — und damit ein einzelnes Wort in einem
     * laengeren Wort keinen falschen Alarm ausloest.
     *
     * @return list<string>
     */
    public function matches(string $haystack): array
    {
        $normalized = self::normalize($haystack);
        $found = [];

        foreach ($this->words as $word) {
            $needle = self::normalize($word);

            if ($needle === '') {
                continue;
            }

            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $normalized) === 1) {
                $found[] = $word;
            }
        }

        return $found;
    }

    /**
     * Kleinschreibung und einfache Leerzeichen. Bewusst ohne Umlaut-Faltung:
     * "ueber" und "über" stehen als getrennte Eintraege in der Wortliste, damit
     * sichtbar bleibt, wonach gesucht wird.
     */
    private static function normalize(string $text): string
    {
        $lower = mb_strtolower($text, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $lower));
    }
}
