<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Search;

/**
 * Uebersetzt Nutzereingaben in eine FTS5-Abfrage.
 *
 * Nutzereingaben duerfen niemals als FTS5-Syntax durchschlagen: Ein Sternchen,
 * ein Bindestrich oder ein Anfuehrungszeichen wuerde die Abfrage sonst
 * umdeuten oder mit einem Syntaxfehler abbrechen. Jeder Begriff wird deshalb
 * als Phrase gequotet; der letzte bekommt eine Praefixsuche.
 */
final class Fts5Query
{
    private const int MAX_TERMS = 8;

    private const int MIN_TERM_LENGTH = 2;

    public static function fromUserInput(string $input): ?string
    {
        $normalized = trim($input);
        if ($normalized === '') {
            return null;
        }

        /** @var list<string> $tokens */
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        $terms = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token) < self::MIN_TERM_LENGTH) {
                continue;
            }

            $terms[] = str_replace('"', '', $token);

            if (\count($terms) >= self::MAX_TERMS) {
                break;
            }
        }

        if ($terms === []) {
            return null;
        }

        $last = array_pop($terms);

        $parts = array_map(static fn(string $term): string => '"' . $term . '"', $terms);
        // Der letzte Begriff als Praefix: "koenigspy" findet "Koenigspython".
        $parts[] = '"' . $last . '"*';

        return implode(' ', $parts);
    }
}
