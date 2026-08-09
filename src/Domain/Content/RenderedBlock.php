<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

/**
 * Ein Block, fertig fuer die Ausgabe.
 *
 * Die Templates bekommen bewusst kein rohes ContentBlock: Sie sollen weder
 * Markdown rendern noch Fremdschluessel aufloesen. Was hier ankommt, ist
 * entweder ausgegebener Text (bereits escapet) oder ein aufgeloestes Objekt.
 */
final readonly class RenderedBlock
{
    /**
     * @param array<string, mixed> $data       die Rohdaten des Blocks
     * @param string               $html       gerendertes Markdown — nur beim Textblock gefuellt, bereits escapet
     * @param object|null          $reference  aufgeloeste Anzeige oder Art, sofern der Block eine benennt
     * @param bool                 $unresolved die Referenz gibt es nicht (mehr)
     */
    public function __construct(
        public BlockType $type,
        public int $position,
        public array $data = [],
        public string $html = '',
        public ?object $reference = null,
        public bool $unresolved = false,
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
    public function ints(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (!\is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $entry) {
            if (\is_int($entry)) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    public function template(): string
    {
        return 'inhalt/bloecke/' . str_replace('-', '_', $this->type->value) . '.html.twig';
    }
}
