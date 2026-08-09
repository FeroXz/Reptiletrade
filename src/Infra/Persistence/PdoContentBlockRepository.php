<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use Reptilienmarkt\Domain\Content\BlockType;
use Reptilienmarkt\Domain\Content\ContentBlock;
use Reptilienmarkt\Domain\Content\ContentBlockRepository;

final readonly class PdoContentBlockRepository implements ContentBlockRepository
{
    public function __construct(private Database $database) {}

    public function forEntry(int $entryId): array
    {
        $blocks = [];

        foreach ($this->database->select(
            'SELECT id, entry_id, position, type, data_json FROM content_blocks
              WHERE entry_id = :entry ORDER BY position ASC, id ASC',
            ['entry' => $entryId],
        ) as $row) {
            /** @var mixed $data */
            $data = json_decode((string) $row['data_json'], true);

            $blocks[] = new ContentBlock(
                id: (int) $row['id'],
                type: BlockType::from((string) $row['type']),
                position: (int) $row['position'],
                data: \is_array($data) ? self::stringKeys($data) : [],
                entryId: (int) $row['entry_id'],
            );
        }

        return $blocks;
    }

    public function replaceAll(int $entryId, array $blocks): void
    {
        // Loeschen und neu schreiben statt einzeln fortschreiben: Der Editor
        // schickt immer die ganze Liste. Wer stattdessen Zeile fuer Zeile
        // abgliche, muesste Luecken, Doppelbelegungen und verwaiste Zeilen
        // getrennt behandeln — drei Fehlerquellen fuer keinen Gewinn.
        //
        // In einer Transaktion, damit zwischen Loeschen und Schreiben niemand
        // einen Eintrag ohne Rumpf sieht.
        $this->database->transaction(static function (Database $database) use ($entryId, $blocks): void {
            $database->execute('DELETE FROM content_blocks WHERE entry_id = :entry', ['entry' => $entryId]);

            $position = 0;
            foreach ($blocks as $block) {
                $database->execute(
                    'INSERT INTO content_blocks (entry_id, position, type, data_json)
                     VALUES (:entry, :position, :type, :data)',
                    [
                        'entry' => $entryId,
                        'position' => $position,
                        'type' => $block->type->value,
                        'data' => json_encode($block->data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                    ],
                );

                ++$position;
            }
        });
    }

    public function deleteForEntry(int $entryId): void
    {
        $this->database->execute('DELETE FROM content_blocks WHERE entry_id = :entry', ['entry' => $entryId]);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            $clean[(string) $key] = $value;
        }

        return $clean;
    }
}
