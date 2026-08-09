<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Search;

use Reptilienmarkt\Domain\Content\ContentSearchIndex;
use Reptilienmarkt\Infra\Persistence\Database;

/**
 * FTS5-Umsetzung des Inhaltsindex.
 *
 * rowid entspricht content_entries.id; ein Trigger raeumt geloeschte Eintraege
 * ab. Der Rumpftext kommt als Reintext herein — die Umwandlung aus Markdown
 * passiert im ContentIndexer, damit es sie nur einmal gibt.
 */
final readonly class Fts5ContentSearchIndex implements ContentSearchIndex
{
    public function __construct(private Database $database) {}

    public function index(int $entryId, string $title, string $excerpt, string $bodyText): void
    {
        $this->database->execute('DELETE FROM content_search WHERE rowid = :id', ['id' => $entryId]);

        $this->database->execute(
            'INSERT INTO content_search (rowid, title, excerpt, body_text) VALUES (:id, :title, :excerpt, :body)',
            ['id' => $entryId, 'title' => $title, 'excerpt' => $excerpt, 'body' => $bodyText],
        );
    }

    public function remove(int $entryId): void
    {
        $this->database->execute('DELETE FROM content_search WHERE rowid = :id', ['id' => $entryId]);
    }

    public function search(string $query, int $limit = 20): array
    {
        // Nutzereingaben duerfen nie als FTS5-Syntax durchschlagen — dieselbe
        // Aufbereitung wie beim Anzeigenindex.
        $match = Fts5Query::fromUserInput($query);

        if ($match === null) {
            return [];
        }

        $ids = [];

        // bm25 gewichtet den Titel am hoechsten: Wer nach "Winterruhe" sucht,
        // meint eher den Beitrag mit diesem Titel als den, in dem das Wort
        // einmal im Rumpf vorkommt.
        foreach ($this->database->select(
            'SELECT rowid FROM content_search
              WHERE content_search MATCH :match
              ORDER BY bm25(content_search, 10.0, 4.0, 1.0)
              LIMIT :limit',
            ['match' => $match, 'limit' => $limit],
        ) as $row) {
            $ids[] = (int) $row['rowid'];
        }

        return $ids;
    }

    public function count(): int
    {
        return (int) $this->database->scalar('SELECT COUNT(*) FROM content_search');
    }

    public function optimize(): void
    {
        $this->database->execute("INSERT INTO content_search(content_search) VALUES ('optimize')");
    }
}
