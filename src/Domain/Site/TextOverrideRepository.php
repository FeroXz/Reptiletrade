<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Site;

/**
 * Persistenzgrenze der Textueberschreibungen.
 *
 * Gelesen wird immer sprachweise und vollstaendig: Eine Seite fragt Dutzende
 * Texte ab, und je Schluessel eine Abfrage waere der Preis fuer nichts.
 */
interface TextOverrideRepository
{
    /**
     * @return array<string, TextOverride> Schluessel => Ueberschreibung
     */
    public function all(string $locale): array;

    public function save(TextOverride $override): void;

    public function delete(string $locale, string $key): void;
}
