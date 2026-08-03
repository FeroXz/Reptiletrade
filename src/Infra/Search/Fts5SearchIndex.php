<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Search;

use Reptilienmarkt\Domain\Search\IndexDocument;
use Reptilienmarkt\Domain\Search\SearchIndex;
use Reptilienmarkt\Infra\Persistence\Database;

/**
 * FTS5-Umsetzung des Volltextindex.
 *
 * Die Tabelle listing_search ist bewusst keine external-content-Tabelle: Sie
 * enthaelt Merkmals- und Artnamen aus Joins, die eine content-Tabelle nicht
 * abbilden kann. rowid entspricht listings.id.
 */
final readonly class Fts5SearchIndex implements SearchIndex
{
    public function __construct(private Database $database) {}

    public function index(IndexDocument $document): void
    {
        $this->database->execute('DELETE FROM listing_search WHERE rowid = :id', ['id' => $document->listingId]);

        $this->database->execute(
            'INSERT INTO listing_search (rowid, title, description, morphs, species)
             VALUES (:id, :title, :description, :morphs, :species)',
            [
                'id' => $document->listingId,
                'title' => $document->title,
                'description' => $document->description,
                'morphs' => $document->morphs(),
                'species' => $document->species(),
            ],
        );
    }

    public function remove(int $listingId): void
    {
        $this->database->execute('DELETE FROM listing_search WHERE rowid = :id', ['id' => $listingId]);
    }

    public function rebuild(iterable $documents): int
    {
        $indexed = 0;

        $this->database->transaction(function (Database $database) use ($documents, &$indexed): void {
            $database->execute('DELETE FROM listing_search');

            $statement = $database->pdo()->prepare(
                'INSERT INTO listing_search (rowid, title, description, morphs, species)
                 VALUES (:id, :title, :description, :morphs, :species)',
            );

            foreach ($documents as $document) {
                $statement->execute([
                    'id' => $document->listingId,
                    'title' => $document->title,
                    'description' => $document->description,
                    'morphs' => $document->morphs(),
                    'species' => $document->species(),
                ]);
                ++$indexed;
            }
        });

        return $indexed;
    }

    public function count(): int
    {
        $value = $this->database->scalar('SELECT COUNT(*) FROM listing_search');

        return (int) (is_numeric($value) ? $value : 0);
    }

    public function optimize(): void
    {
        $this->database->execute("INSERT INTO listing_search(listing_search) VALUES ('optimize')");
    }
}
