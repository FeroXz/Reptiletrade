<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Content\ContentEntry;
use Reptilienmarkt\Domain\Content\ContentEntryRepository;
use Reptilienmarkt\Domain\Content\ContentStatus;
use Reptilienmarkt\Domain\Content\ContentTemplate;
use Reptilienmarkt\Domain\Content\ContentType;
use Reptilienmarkt\Support\Timestamp;

final readonly class PdoContentEntryRepository implements ContentEntryRepository
{
    private const string COLUMNS = 'id, type, slug, path, parent_id, title, excerpt, status, template,
             meta_title, meta_description, noindex, locale, published_at,
             created_at, updated_at, author_id, updated_by, sort_order';

    public function __construct(private Database $database) {}

    public function findById(int $id): ?ContentEntry
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM content_entries WHERE id = :id',
            ['id' => $id],
        );

        return $row === null ? null : self::map($row);
    }

    public function findByPath(string $path): ?ContentEntry
    {
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM content_entries WHERE path = :path',
            ['path' => $path],
        );

        return $row === null ? null : self::map($row);
    }

    public function findBySlug(ContentType $type, ?int $parentId, string $slug): ?ContentEntry
    {
        // IS statt = beim Elternteil: NULL = NULL ist in SQL niemals wahr, und
        // genau die Wurzelseiten haetten sonst nie einen Treffer.
        $row = $this->database->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM content_entries
              WHERE type = :type AND parent_id IS :parent AND slug = :slug',
            ['type' => $type->value, 'parent' => $parentId, 'slug' => $slug],
        );

        return $row === null ? null : self::map($row);
    }

    public function save(ContentEntry $entry): int
    {
        $parameters = [
            'type' => $entry->type->value,
            'slug' => $entry->slug,
            'path' => $entry->path,
            'parent_id' => $entry->parentId,
            'title' => $entry->title,
            'excerpt' => $entry->excerpt,
            'status' => $entry->status->value,
            'template' => $entry->template->value,
            'meta_title' => $entry->metaTitle,
            'meta_description' => $entry->metaDescription,
            'noindex' => $entry->noindex ? 1 : 0,
            'locale' => $entry->locale,
            'published_at' => Timestamp::utcOrNull($entry->publishedAt),
            'updated_at' => Timestamp::utc($entry->updatedAt ?? new DateTimeImmutable()),
            'updated_by' => $entry->updatedBy,
            'sort_order' => $entry->sortOrder,
        ];

        if ($entry->id === null) {
            $this->database->execute(
                'INSERT INTO content_entries
                     (type, slug, path, parent_id, title, excerpt, status, template, meta_title,
                      meta_description, noindex, locale, published_at, created_at,
                      updated_at, author_id, updated_by, sort_order)
                 VALUES
                     (:type, :slug, :path, :parent_id, :title, :excerpt, :status, :template, :meta_title,
                      :meta_description, :noindex, :locale, :published_at, :created_at,
                      :updated_at, :author_id, :updated_by, :sort_order)',
                $parameters + [
                    'created_at' => Timestamp::utc($entry->createdAt ?? new DateTimeImmutable()),
                    'author_id' => $entry->authorId,
                ],
            );

            return $this->database->lastInsertId();
        }

        // created_at und author_id bleiben, wie sie waren: Wer einen Eintrag
        // bearbeitet, aendert nicht, wer ihn angelegt hat.
        $this->database->execute(
            'UPDATE content_entries
                SET type = :type, slug = :slug, path = :path, parent_id = :parent_id, title = :title,
                    excerpt = :excerpt, status = :status, template = :template, meta_title = :meta_title,
                    meta_description = :meta_description, noindex = :noindex,
                    locale = :locale, published_at = :published_at, updated_at = :updated_at,
                    updated_by = :updated_by, sort_order = :sort_order
              WHERE id = :id',
            $parameters + ['id' => $entry->id],
        );

        return $entry->id;
    }

    public function delete(int $id): void
    {
        $this->database->execute('DELETE FROM content_entries WHERE id = :id', ['id' => $id]);
    }

    public function children(?int $parentId): array
    {
        return $this->mapAll($this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM content_entries
              WHERE type = :type AND parent_id IS :parent
              ORDER BY sort_order ASC, title ASC',
            ['type' => ContentType::Seite->value, 'parent' => $parentId],
        ));
    }

    public function descendants(int $parentId): array
    {
        // Rekursives CTE — hier ist es richtig: Es laeuft nur beim Umbenennen
        // einer Seite, nicht bei jedem Aufruf. Fuer den Aufruf gibt es die
        // Spalte path.
        return $this->mapAll($this->database->select(
            'WITH RECURSIVE nachfahren(id) AS (
                 SELECT id FROM content_entries WHERE parent_id = :parent
                 UNION ALL
                 SELECT e.id FROM content_entries e JOIN nachfahren n ON e.parent_id = n.id
             )
             SELECT ' . self::COLUMNS . ' FROM content_entries
              WHERE id IN (SELECT id FROM nachfahren)
              ORDER BY length(path) ASC, path ASC',
            ['parent' => $parentId],
        ));
    }

    public function published(ContentType $type, int $limit, int $offset = 0): array
    {
        return $this->mapAll($this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM content_entries
              WHERE type = :type AND status = :status
              ORDER BY published_at DESC, id DESC
              LIMIT :limit OFFSET :offset',
            [
                'type' => $type->value,
                'status' => ContentStatus::Veroeffentlicht->value,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ));
    }

    public function countPublished(ContentType $type): int
    {
        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM content_entries WHERE type = :type AND status = :status',
            ['type' => $type->value, 'status' => ContentStatus::Veroeffentlicht->value],
        );
    }

    public function due(DateTimeImmutable $now, int $limit = 100): array
    {
        return $this->mapAll($this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM content_entries
              WHERE status = :status AND published_at <= :now
              ORDER BY published_at ASC
              LIMIT :limit',
            ['status' => ContentStatus::Geplant->value, 'now' => Timestamp::utc($now), 'limit' => $limit],
        ));
    }

    public function search(?ContentType $type, ?ContentStatus $status, ?string $query, int $limit = 100): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM content_entries WHERE 1 = 1';
        $parameters = ['limit' => $limit];

        if ($type !== null) {
            $sql .= ' AND type = :type';
            $parameters['type'] = $type->value;
        }

        if ($status !== null) {
            $sql .= ' AND status = :status';
            $parameters['status'] = $status->value;
        }

        if ($query !== null && trim($query) !== '') {
            // LIKE, nicht FTS5: Die Verwaltungsliste sucht auch in Entwuerfen,
            // und der Volltextindex traegt nur Veroeffentlichtes.
            $sql .= ' AND (title LIKE :q OR path LIKE :q)';
            $parameters['q'] = '%' . trim($query) . '%';
        }

        $sql .= ' ORDER BY updated_at DESC, id DESC LIMIT :limit';

        return $this->mapAll($this->database->select($sql, $parameters));
    }

    public function allPublished(): iterable
    {
        foreach ($this->database->select(
            'SELECT ' . self::COLUMNS . ' FROM content_entries
              WHERE status = :status
              ORDER BY type ASC, published_at DESC',
            ['status' => ContentStatus::Veroeffentlicht->value],
        ) as $row) {
            yield self::map($row);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<ContentEntry>
     */
    private function mapAll(array $rows): array
    {
        return array_map(static fn(array $row): ContentEntry => self::map($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function map(array $row): ContentEntry
    {
        return new ContentEntry(
            id: (int) $row['id'],
            type: ContentType::from((string) $row['type']),
            slug: (string) $row['slug'],
            path: (string) $row['path'],
            title: (string) $row['title'],
            status: ContentStatus::from((string) $row['status']),
            template: ContentTemplate::from((string) $row['template']),
            parentId: $row['parent_id'] === null ? null : (int) $row['parent_id'],
            excerpt: (string) $row['excerpt'],
            metaTitle: $row['meta_title'] === null ? null : (string) $row['meta_title'],
            metaDescription: $row['meta_description'] === null ? null : (string) $row['meta_description'],
            noindex: (int) $row['noindex'] === 1,
            locale: (string) $row['locale'],
            publishedAt: Timestamp::parse($row['published_at'] === null ? null : (string) $row['published_at']),
            createdAt: Timestamp::parse((string) $row['created_at']),
            updatedAt: Timestamp::parse((string) $row['updated_at']),
            authorId: $row['author_id'] === null ? null : (int) $row['author_id'],
            updatedBy: $row['updated_by'] === null ? null : (int) $row['updated_by'],
            sortOrder: (int) $row['sort_order'],
        );
    }
}
