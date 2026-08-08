<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use DateTimeImmutable;

final readonly class ContentTerm
{
    public function __construct(
        public ?int $id,
        public Taxonomy $taxonomy,
        public string $slug,
        public string $name,
        public string $description = '',
        public string $locale = ContentEntry::DEFAULT_LOCALE,
        public ?DateTimeImmutable $createdAt = null,
        /** Nur gefuellt, wo die Abfrage danach gefragt hat. */
        public int $entryCount = 0,
    ) {}

    public function path(): string
    {
        return $this->taxonomy->hasArchive() ? '/news/kategorie/' . $this->slug . '/' : '';
    }
}
