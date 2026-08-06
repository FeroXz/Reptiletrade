<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Site\TextOverride;
use Reptilienmarkt\Domain\Site\TextOverrideRepository;
use Reptilienmarkt\Support\Timestamp;
use Reptilienmarkt\Support\TranslationOverrides;

/**
 * Die Textueberschreibungen aus der Tabelle ui_texts.
 *
 * Eine Klasse fuer zwei Schnittstellen: Der Uebersetzer braucht nur
 * Schluessel und Text (TranslationOverrides), die Verwaltung zusaetzlich, wer
 * wann geaendert hat (TextOverrideRepository). Zwei Klassen haetten zwei
 * Zwischenspeicher fuer dieselbe Tabelle bedeutet — und die eine haette die
 * Aenderungen der anderen nicht mitbekommen.
 */
final class PdoTextOverrideRepository implements TextOverrideRepository, TranslationOverrides
{
    /** @var array<string, array<string, TextOverride>> */
    private array $cache = [];

    public function __construct(private readonly Database $database) {}

    public function all(string $locale): array
    {
        if (isset($this->cache[$locale])) {
            return $this->cache[$locale];
        }

        $rows = $this->database->select(
            'SELECT locale, text_key, value, updated_at, updated_by FROM ui_texts WHERE locale = :locale',
            ['locale' => $locale],
        );

        $overrides = [];
        foreach ($rows as $row) {
            $key = (string) $row['text_key'];

            $overrides[$key] = new TextOverride(
                (string) $row['locale'],
                $key,
                (string) $row['value'],
                new DateTimeImmutable((string) $row['updated_at']),
                $row['updated_by'] === null ? null : (int) $row['updated_by'],
            );
        }

        return $this->cache[$locale] = $overrides;
    }

    public function forLocale(string $locale): array
    {
        $texts = [];

        foreach ($this->all($locale) as $key => $override) {
            $texts[$key] = $override->value;
        }

        return $texts;
    }

    public function save(TextOverride $override): void
    {
        $this->database->execute(
            'INSERT INTO ui_texts (locale, text_key, value, updated_at, updated_by)
             VALUES (:locale, :key, :value, :updated_at, :updated_by)
             ON CONFLICT(locale, text_key) DO UPDATE SET
                value = excluded.value,
                updated_at = excluded.updated_at,
                updated_by = excluded.updated_by',
            [
                'locale' => $override->locale,
                'key' => $override->key,
                'value' => $override->value,
                'updated_at' => Timestamp::utc($override->updatedAt),
                'updated_by' => $override->updatedBy,
            ],
        );

        unset($this->cache[$override->locale]);
    }

    public function delete(string $locale, string $key): void
    {
        $this->database->execute(
            'DELETE FROM ui_texts WHERE locale = :locale AND text_key = :key',
            ['locale' => $locale, 'key' => $key],
        );

        unset($this->cache[$locale]);
    }
}
