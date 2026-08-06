<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Site;

use DateTimeImmutable;

/**
 * Ein Oberflaechentext, wie ihn die Verwaltung sieht: der ausgelieferte Text,
 * der zurzeit gueltige, und ob jemand ihn geaendert hat.
 */
final readonly class UiText
{
    public function __construct(
        public string $key,
        public string $original,
        public string $current,
        public bool $isOverridden = false,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}

    /**
     * Der Bereich vor dem ersten Punkt: "postfach" aus "postfach.titel".
     *
     * Danach ist die Verwaltungsansicht gegliedert — 274 Texte in einer Liste
     * findet niemand, 274 Texte in zwei Dutzend Bereichen schon.
     */
    public function section(): string
    {
        $position = strpos($this->key, '.');

        return $position === false ? $this->key : substr($this->key, 0, $position);
    }

    /**
     * Die Platzhalter des ausgelieferten Textes, z. B. ["anzahl"] aus
     * "Noch {anzahl} Tage".
     *
     * @return list<string>
     */
    public function placeholders(): array
    {
        return self::placeholdersIn($this->original);
    }

    /**
     * @return list<string>
     */
    public static function placeholdersIn(string $text): array
    {
        preg_match_all('/\{([a-z0-9_]+)\}/i', $text, $treffer);

        return array_values(array_unique($treffer[1]));
    }

    public function matches(string $search): bool
    {
        $needle = mb_strtolower(trim($search));

        if ($needle === '') {
            return true;
        }

        return str_contains(mb_strtolower($this->key), $needle)
            || str_contains(mb_strtolower($this->current), $needle)
            || str_contains(mb_strtolower($this->original), $needle);
    }
}
