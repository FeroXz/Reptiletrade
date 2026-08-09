<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use Reptilienmarkt\Domain\Content\ContentStatus;
use Reptilienmarkt\Domain\Content\ContentTerm;
use Reptilienmarkt\Domain\Content\ContentTermRepository;
use Reptilienmarkt\Domain\Content\Taxonomy;
use Reptilienmarkt\Support\Slugger;
use Reptilienmarkt\Support\Timestamp;
use RuntimeException;

final readonly class PdoContentTermRepository implements ContentTermRepository
{
    public function __construct(private Database $database) {}

    public function findById(int $id): ?ContentTerm
    {
        $row = $this->database->selectOne(
            'SELECT id, taxonomy, slug, name, description, locale, created_at FROM content_terms WHERE id = :id',
            ['id' => $id],
        );

        return $row === null ? null : self::map($row);
    }

    public function findBySlug(Taxonomy $taxonomy, string $slug): ?ContentTerm
    {
        $row = $this->database->selectOne(
            'SELECT id, taxonomy, slug, name, description, locale, created_at
               FROM content_terms WHERE taxonomy = :taxonomy AND slug = :slug',
            ['taxonomy' => $taxonomy->value, 'slug' => $slug],
        );

        return $row === null ? null : self::map($row);
    }

    public function ensure(Taxonomy $taxonomy, string $name): ContentTerm
    {
        $name = trim($name);
        $slug = Slugger::slug($name);

        $vorhanden = $this->findBySlug($taxonomy, $slug);

        if ($vorhanden !== null) {
            return $vorhanden;
        }

        $this->database->execute(
            'INSERT INTO content_terms (taxonomy, slug, name, created_at) VALUES (:taxonomy, :slug, :name, :now)',
            ['taxonomy' => $taxonomy->value, 'slug' => $slug, 'name' => $name, 'now' => Timestamp::now()],
        );

        $angelegt = $this->findBySlug($taxonomy, $slug);

        // Kann nur fehlen, wenn zwischen INSERT und SELECT jemand geloescht
        // hat. Dann ist eine Ausnahme richtiger als ein stiller Null-Wert.
        return $angelegt ?? throw new RuntimeException('Der Begriff konnte nicht angelegt werden: ' . $name);
    }

    public function all(Taxonomy $taxonomy, bool $onlyUsed = false): array
    {
        // Gezaehlt wird nur Veroeffentlichtes: Eine Kategorie, deren Archiv
        // leer ist, weil alles darin noch Entwurf ist, soll in der oeffentlichen
        // Liste nicht auftauchen.
        $sql = 'SELECT t.id, t.taxonomy, t.slug, t.name, t.description, t.locale, t.created_at,
                       COUNT(e.id) AS anzahl
                  FROM content_terms t
                  LEFT JOIN content_entry_terms et ON et.term_id = t.id
                  LEFT JOIN content_entries e ON e.id = et.entry_id AND e.status = :status
                 WHERE t.taxonomy = :taxonomy
                 GROUP BY t.id';

        if ($onlyUsed) {
            $sql .= ' HAVING anzahl > 0';
        }

        $sql .= ' ORDER BY t.name ASC';

        $terms = [];
        foreach ($this->database->select(
            $sql,
            ['taxonomy' => $taxonomy->value, 'status' => ContentStatus::Veroeffentlicht->value],
        ) as $row) {
            $terms[] = self::map($row, (int) ($row['anzahl'] ?? 0));
        }

        return $terms;
    }

    public function forEntry(int $entryId): array
    {
        $terms = [];

        foreach ($this->database->select(
            'SELECT t.id, t.taxonomy, t.slug, t.name, t.description, t.locale, t.created_at
               FROM content_terms t
               JOIN content_entry_terms et ON et.term_id = t.id
              WHERE et.entry_id = :entry
              ORDER BY t.taxonomy ASC, t.name ASC',
            ['entry' => $entryId],
        ) as $row) {
            $terms[] = self::map($row);
        }

        return $terms;
    }

    public function assign(int $entryId, array $termIds): void
    {
        // Ersetzen statt abgleichen — dieselbe Linie wie bei den Bloecken: Der
        // Editor schickt immer die vollstaendige Auswahl.
        $this->database->transaction(static function (Database $database) use ($entryId, $termIds): void {
            $database->execute('DELETE FROM content_entry_terms WHERE entry_id = :entry', ['entry' => $entryId]);

            foreach (array_unique($termIds) as $termId) {
                $database->execute(
                    'INSERT OR IGNORE INTO content_entry_terms (entry_id, term_id) VALUES (:entry, :term)',
                    ['entry' => $entryId, 'term' => $termId],
                );
            }
        });
    }

    public function entryIds(int $termId, int $limit, int $offset = 0): array
    {
        $ids = [];

        foreach ($this->database->select(
            'SELECT e.id FROM content_entries e
               JOIN content_entry_terms et ON et.entry_id = e.id
              WHERE et.term_id = :term AND e.status = :status
              ORDER BY e.published_at DESC, e.id DESC
              LIMIT :limit OFFSET :offset',
            [
                'term' => $termId,
                'status' => ContentStatus::Veroeffentlicht->value,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    public function countEntries(int $termId): int
    {
        return (int) $this->database->scalar(
            'SELECT COUNT(*) FROM content_entry_terms et
               JOIN content_entries e ON e.id = et.entry_id
              WHERE et.term_id = :term AND e.status = :status',
            ['term' => $termId, 'status' => ContentStatus::Veroeffentlicht->value],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function map(array $row, int $entryCount = 0): ContentTerm
    {
        return new ContentTerm(
            id: (int) $row['id'],
            taxonomy: Taxonomy::from((string) $row['taxonomy']),
            slug: (string) $row['slug'],
            name: (string) $row['name'],
            description: (string) $row['description'],
            locale: (string) $row['locale'],
            createdAt: Timestamp::parse((string) $row['created_at']),
            entryCount: $entryCount,
        );
    }
}
