<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

/**
 * Ein Block im Rumpf eines Eintrags.
 *
 * Die Nutzdaten liegen als Feld vor, nicht als eigene Klasse je Typ: Der Editor
 * schreibt sie aus einem Formular, der Renderer liest sie mit festen
 * Schluesseln. Was ein Typ braucht, steht in BlockData — dort und nur dort
 * werden die Werte geprueft.
 */
final readonly class ContentBlock
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public ?int $id,
        public BlockType $type,
        public int $position,
        public array $data = [],
        public ?int $entryId = null,
    ) {}

    public function string(string $key, string $default = ''): string
    {
        $value = $this->data[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->data[$key] ?? null;

        return \is_int($value) ? $value : $default;
    }

    /**
     * @return list<int>
     */
    public function intList(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (!\is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $entry) {
            if (\is_int($entry)) {
                $ids[] = $entry;
            }
        }

        return $ids;
    }

    /**
     * Die Medien-IDs, die dieser Block einbindet — Grundlage der Eintraege in
     * media_usages. Ohne diese Auskunft loescht jemand ein Bild, das auf drei
     * Seiten steht, und merkt es nie.
     *
     * @return list<int>
     */
    public function mediaIds(): array
    {
        return match ($this->type) {
            BlockType::Bild => ($id = $this->int('media_id')) > 0 ? [$id] : [],
            BlockType::Galerie => array_values(array_filter($this->intList('media_ids'), static fn(int $id): bool => $id > 0)),
            default => [],
        };
    }

    /**
     * Der durchsuchbare Text dieses Blocks. Markdown-Auszeichnung bleibt darin
     * stehen — FTS5 zerlegt ohnehin in Woerter, und ein zweiter Renderer nur
     * fuer den Index waere eine zweite Wahrheit ueber denselben Text.
     */
    public function searchText(): string
    {
        if (!$this->type->isSearchable()) {
            return '';
        }

        $parts = [];
        foreach (['text', 'titel', 'zitat', 'quelle', 'label'] as $key) {
            $value = $this->string($key);
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode("\n", $parts);
    }

    public function withPosition(int $position): self
    {
        return new self($this->id, $this->type, $position, $this->data, $this->entryId);
    }

    public function withEntry(int $entryId): self
    {
        return new self($this->id, $this->type, $this->position, $this->data, $entryId);
    }
}
