<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Search;

use Generator;
use Reptilienmarkt\Domain\Search\IndexDocument;
use Reptilienmarkt\Domain\Search\SearchIndex;
use Reptilienmarkt\Infra\Persistence\Database;

/**
 * Stellt die Indexdokumente aus Anzeige, Art und Merkmalen zusammen.
 *
 * Merkmalsnamen und ihre Aliases werden ueber json_each aus der JSON-Spalte
 * morphs.aliases herausgezogen — so findet die Suche "Pied" ebenso wie "Piebald".
 */
final readonly class ListingIndexer
{
    private const string DOCUMENT_SQL = <<<'SQL'
        SELECT
            l.id,
            l.title,
            l.description,
            s.scientific_name,
            s.common_name_de,
            (
                SELECT group_concat(term, ' ') FROM (
                    SELECT m.name AS term
                      FROM listing_morphs lm
                      JOIN morphs m ON m.id = lm.morph_id
                     WHERE lm.listing_id = l.id
                    UNION
                    SELECT alias.value AS term
                      FROM listing_morphs lm
                      JOIN morphs m ON m.id = lm.morph_id,
                           json_each(m.aliases) alias
                     WHERE lm.listing_id = l.id
                )
            ) AS morph_terms
        FROM listings l
        JOIN species s ON s.id = l.species_id
        SQL;

    public function __construct(
        private Database $database,
        private SearchIndex $index,
    ) {}

    /**
     * Nach jedem Speichern einer Anzeige aufzurufen.
     */
    public function indexListing(int $listingId): void
    {
        $row = $this->database->selectOne(
            self::DOCUMENT_SQL . ' WHERE l.id = :id',
            ['id' => $listingId],
        );

        if ($row === null) {
            $this->index->remove($listingId);

            return;
        }

        $this->index->index($this->toDocument($row));
    }

    /**
     * Nimmt eine Anzeige aus dem Index — etwa wenn die Moderation sie sperrt.
     */
    public function removeListing(int $listingId): void
    {
        $this->index->remove($listingId);
    }

    public function rebuildAll(): int
    {
        return $this->index->rebuild($this->documents());
    }

    /**
     * @return Generator<int, IndexDocument>
     */
    private function documents(): Generator
    {
        $statement = $this->database->pdo()->query(self::DOCUMENT_SQL . ' ORDER BY l.id');
        if ($statement === false) {
            return;
        }

        while (true) {
            /** @var array<string, mixed>|false $row */
            $row = $statement->fetch();
            if ($row === false) {
                break;
            }

            yield $this->toDocument($row);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toDocument(array $row): IndexDocument
    {
        $morphTerms = [];
        if (\is_string($row['morph_terms']) && $row['morph_terms'] !== '') {
            $morphTerms = array_values(array_unique(explode(',', $row['morph_terms'])));
        }

        return new IndexDocument(
            (int) $row['id'],
            (string) $row['title'],
            (string) $row['description'],
            $morphTerms,
            [(string) $row['scientific_name'], (string) $row['common_name_de']],
        );
    }
}
