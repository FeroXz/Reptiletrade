<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Legal;

use DateTimeImmutable;
use Reptilienmarkt\Legal\LegalText;
use Reptilienmarkt\Legal\LegalTextRepository;

final class InMemoryLegalTextRepository implements LegalTextRepository
{
    /** @var array<string, LegalText> */
    private array $texts = [];

    public function find(string $key, string $jurisdiction = 'DE'): ?LegalText
    {
        return $this->texts[$key . '|' . $jurisdiction] ?? null;
    }

    public function all(): array
    {
        return array_values($this->texts);
    }

    public function save(LegalText $text): int
    {
        $this->texts[$text->key . '|' . $text->jurisdiction] = $text;

        return \count($this->texts);
    }

    public function insertIfMissing(LegalText $text): bool
    {
        $index = $text->key . '|' . $text->jurisdiction;
        if (isset($this->texts[$index])) {
            return false;
        }

        $this->texts[$index] = $text;

        return true;
    }

    public function markReviewed(string $key, string $jurisdiction, int $userId): void
    {
        $index = $key . '|' . $jurisdiction;
        $text = $this->texts[$index] ?? null;
        if ($text === null) {
            return;
        }

        $this->texts[$index] = new LegalText(
            $text->id,
            $text->key,
            $text->title,
            $text->body,
            $text->jurisdiction,
            $text->sourceReference,
            new DateTimeImmutable(),
        );
    }

    /**
     * Laedt die echten Ausgangstexte aus data/legal_texts.json, damit Tests
     * gegen dieselben Texte laufen wie der Betrieb.
     */
    public function loadBundled(string $file): void
    {
        /** @var array{texte?: list<array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);

        foreach ($data['texte'] ?? [] as $row) {
            $this->insertIfMissing(new LegalText(
                null,
                (string) $row['key'],
                (string) $row['title'],
                (string) $row['body'],
                isset($row['jurisdiction']) && \is_string($row['jurisdiction']) ? $row['jurisdiction'] : 'DE',
                isset($row['source_reference']) && \is_string($row['source_reference']) ? $row['source_reference'] : null,
            ));
        }
    }
}
