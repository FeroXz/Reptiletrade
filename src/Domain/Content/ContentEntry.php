<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use DateTimeImmutable;

/**
 * Eine Seite oder ein Beitrag — der Kopf, ohne Bloecke.
 *
 * Kopf und Rumpf sind getrennt, weil die Uebersichten (Liste, Menue, Sitemap,
 * Feed) den Rumpf nie brauchen. Wer beides zusammen will, holt sich die Bloecke
 * ueber das ContentBlockRepository dazu.
 */
final readonly class ContentEntry
{
    public function __construct(
        public ?int $id,
        public ContentType $type,
        public string $slug,
        public string $path,
        public string $title,
        public ContentStatus $status = ContentStatus::Entwurf,
        public ContentTemplate $template = ContentTemplate::Standard,
        public ?int $parentId = null,
        public string $excerpt = '',
        public ?string $metaTitle = null,
        public ?string $metaDescription = null,
        public ?int $ogImageId = null,
        public bool $noindex = false,
        public string $locale = self::DEFAULT_LOCALE,
        public ?DateTimeImmutable $publishedAt = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public ?int $authorId = null,
        public ?int $updatedBy = null,
        public int $sortOrder = 0,
    ) {}

    /**
     * Die Oberflaeche ist mehrsprachig, die Inhalte sind es nicht. Die Spalte
     * steht trotzdem von Anfang an in jeder Inhaltstabelle: SQLite kann CHECK
     * und Fremdschluessel nicht nachtraeglich aendern, und eine spaetere
     * Erweiterung soll keinen Tabellenumbau brauchen.
     */
    public const string DEFAULT_LOCALE = 'de-DE';

    public function isPublic(): bool
    {
        return $this->status->isPublic();
    }

    /**
     * Der Titel fuer <title> — mit Rueckfall auf den Seitentitel.
     */
    public function seoTitle(): string
    {
        $meta = $this->metaTitle;

        return $meta !== null && trim($meta) !== '' ? $meta : $this->title;
    }

    /**
     * Die Beschreibung fuer <meta name="description"> — mit Rueckfall auf den
     * Anrisstext. Leer bleibt leer: Eine erfundene Beschreibung ist schlechter
     * als keine.
     */
    public function seoDescription(): ?string
    {
        foreach ([$this->metaDescription, $this->excerpt] as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * Das Jahr, unter dem ein Beitrag adressiert wird.
     */
    public function year(): ?string
    {
        return ($this->publishedAt ?? $this->createdAt)?->format('Y');
    }

    public function withId(int $id): self
    {
        return $this->with(id: $id);
    }

    public function withPath(string $path): self
    {
        return $this->with(path: $path);
    }

    public function withSlug(string $slug, string $path): self
    {
        return $this->with(slug: $slug, path: $path);
    }

    public function withStatus(ContentStatus $status, ?DateTimeImmutable $publishedAt = null): self
    {
        return $this->with(
            status: $status,
            publishedAt: $status->requiresPublishedAt() ? ($publishedAt ?? $this->publishedAt) : $this->publishedAt,
        );
    }

    public function withTouch(DateTimeImmutable $moment, ?int $userId): self
    {
        return $this->with(updatedAt: $moment, updatedBy: $userId);
    }

    /**
     * Ein Kopierkonstruktor statt eines Dutzends fast gleicher with*-Methoden.
     * Er bleibt privat: Von aussen sollen nur die Aenderungen moeglich sein,
     * die auch fachlich vorkommen.
     */
    private function with(
        ?int $id = null,
        ?string $slug = null,
        ?string $path = null,
        ?ContentStatus $status = null,
        ?DateTimeImmutable $publishedAt = null,
        ?DateTimeImmutable $updatedAt = null,
        ?int $updatedBy = null,
    ): self {
        return new self(
            id: $id ?? $this->id,
            type: $this->type,
            slug: $slug ?? $this->slug,
            path: $path ?? $this->path,
            title: $this->title,
            status: $status ?? $this->status,
            template: $this->template,
            parentId: $this->parentId,
            excerpt: $this->excerpt,
            metaTitle: $this->metaTitle,
            metaDescription: $this->metaDescription,
            ogImageId: $this->ogImageId,
            noindex: $this->noindex,
            locale: $this->locale,
            publishedAt: $publishedAt ?? $this->publishedAt,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt ?? $this->updatedAt,
            authorId: $this->authorId,
            updatedBy: $updatedBy ?? $this->updatedBy,
            sortOrder: $this->sortOrder,
        );
    }
}
