<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use DateTimeImmutable;

/**
 * Eine gespeicherte Fassung: Kopf und Bloecke zusammen.
 *
 * Das Abbild liegt als Feld vor und wird beim Zuruecksetzen wieder zu Entitaeten
 * gemacht. Absichtlich kein typisiertes Objekt: Eine Revision muss auch dann
 * noch lesbar sein, wenn der Kopf inzwischen ein Feld mehr hat — und ein
 * Abbild, das sich nur mit der heutigen Klasse entpacken laesst, waere genau
 * dann wertlos, wenn man ihn braucht.
 */
final readonly class ContentRevision
{
    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        public int $entryId,
        public int $revisionNo,
        public array $snapshot,
        public ?int $authorId,
        public string $comment,
        public ?DateTimeImmutable $createdAt,
    ) {}

    public function title(): string
    {
        $title = $this->snapshot['title'] ?? null;

        return \is_string($title) ? $title : '';
    }

    public function status(): string
    {
        $status = $this->snapshot['status'] ?? null;

        return \is_string($status) ? $status : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function blocks(): array
    {
        $blocks = $this->snapshot['blocks'] ?? null;

        if (!\is_array($blocks)) {
            return [];
        }

        $result = [];
        foreach ($blocks as $block) {
            if (\is_array($block)) {
                $clean = [];
                foreach ($block as $key => $value) {
                    $clean[(string) $key] = $value;
                }
                $result[] = $clean;
            }
        }

        return $result;
    }
}
