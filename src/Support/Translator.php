<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support;

use RuntimeException;

/**
 * Uebersetzt Schluessel in Texte der eingestellten Sprache.
 *
 * de-DE ist die Basis: Fehlt ein Schluessel in einer anderen Sprache, greift
 * der deutsche Text. Fehlt er auch dort, wird der Schluessel selbst
 * zurueckgegeben — eine Seite mit "postfach.leer" ist haesslich, aber sie
 * laeuft, und der Fehler faellt sofort auf.
 *
 * Platzhalter stehen in geschweiften Klammern: "Noch {anzahl} Tage".
 *
 * Ueber dem Dateikatalog liegen die Ueberschreibungen der Verwaltung. Die
 * Reihenfolge ist damit: Ueberschreibung der Sprache, Datei der Sprache,
 * Ueberschreibung der Basissprache, Datei der Basissprache. Ohne
 * Ueberschreibungsquelle verhaelt sich der Uebersetzer wie zuvor — bin/-Skripte
 * und Tests bauen ihn weiterhin mit einem Verzeichnis und sonst nichts.
 */
final class Translator
{
    public const string BASE_LOCALE = 'de-DE';

    /** @var array<string, array<string, string>> */
    private array $catalogues = [];

    /** @var array<string, string> */
    private array $missing = [];

    public function __construct(
        private readonly string $directory,
        private string $locale = self::BASE_LOCALE,
        private readonly ?TranslationOverrides $overrides = null,
    ) {}

    public function locale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    public function translate(string $key, array $parameters = []): string
    {
        $text = $this->lookup($this->locale, $key)
            ?? $this->lookup(self::BASE_LOCALE, $key);

        if ($text === null) {
            $this->missing[$key] = $key;

            return $key;
        }

        return $this->interpolate($text, $parameters);
    }

    /**
     * Ein- und Mehrzahl. Bewusst nur zwei Formen: Deutsch, Englisch und die
     * anderen geplanten Sprachen kommen damit aus.
     *
     * @param array<string, string|int|float> $parameters
     */
    public function choose(string $key, int $count, array $parameters = []): string
    {
        $variant = $this->translate($key . ($count === 1 ? '.eins' : '.viele'), $parameters + ['anzahl' => $count]);

        // Fehlt die Mehrzahlform, ist der Schluessel selbst zurueckgekommen —
        // dann lieber den Grundschluessel versuchen als ".viele" anzeigen.
        return $variant === $key . ($count === 1 ? '.eins' : '.viele')
            ? $this->translate($key, $parameters + ['anzahl' => $count])
            : $variant;
    }

    public function has(string $key): bool
    {
        return $this->lookup($this->locale, $key) !== null || $this->lookup(self::BASE_LOCALE, $key) !== null;
    }

    /**
     * Schluessel ohne Text — fuer einen Test, der die Kataloge vergleicht.
     *
     * @return list<string>
     */
    public function missingKeys(): array
    {
        return array_values($this->missing);
    }

    /**
     * Alle Schluessel des ausgelieferten Katalogs — die Liste, aus der die
     * Textverwaltung ihre Ansicht baut. Ueberschreibungen bringen keine neuen
     * Schluessel hervor: Was in keinem Template steht, ist auch nicht
     * aenderbar.
     *
     * @return list<string>
     */
    public function keys(?string $locale = null): array
    {
        return array_keys($this->catalogue($locale ?? self::BASE_LOCALE));
    }

    /**
     * Der ausgelieferte Text ohne Ueberschreibung.
     *
     * Die Verwaltung braucht ihn zweimal: als Vergleich neben dem geaenderten
     * Text und als Ziel des Zuruecksetzens.
     */
    public function original(string $key, ?string $locale = null): ?string
    {
        $locale ??= $this->locale;

        return $this->catalogue($locale)[$key] ?? $this->catalogue(self::BASE_LOCALE)[$key] ?? null;
    }

    private function lookup(string $locale, string $key): ?string
    {
        return $this->overrides($locale)[$key] ?? $this->catalogue($locale)[$key] ?? null;
    }

    /**
     * Bewusst ohne eigenen Zwischenspeicher: Sonst zeigte der Uebersetzer nach
     * einer Textaenderung im selben Aufruf noch den alten Stand. Das
     * Zwischenspeichern ist Sache der Ueberschreibungsquelle — sie weiss, wann
     * sie ungueltig wird.
     *
     * @return array<string, string>
     */
    private function overrides(string $locale): array
    {
        return $this->overrides?->forLocale($locale) ?? [];
    }

    /**
     * @return array<string, string>
     */
    private function catalogue(string $locale): array
    {
        if (isset($this->catalogues[$locale])) {
            return $this->catalogues[$locale];
        }

        $file = $this->directory . '/' . $locale . '.php';

        if (!is_file($file)) {
            return $this->catalogues[$locale] = [];
        }

        /** @var mixed $loaded */
        $loaded = require $file;

        if (!\is_array($loaded)) {
            throw new RuntimeException(\sprintf('Sprachdatei %s muss ein Array zurueckgeben.', $file));
        }

        $catalogue = [];
        foreach ($loaded as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $catalogue[$key] = $value;
            }
        }

        return $this->catalogues[$locale] = $catalogue;
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    private function interpolate(string $text, array $parameters): string
    {
        if ($parameters === []) {
            return $text;
        }

        $replacements = [];
        foreach ($parameters as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }

        return strtr($text, $replacements);
    }
}
