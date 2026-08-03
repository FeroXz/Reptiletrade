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

    private function lookup(string $locale, string $key): ?string
    {
        $catalogue = $this->catalogue($locale);

        return $catalogue[$key] ?? null;
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
