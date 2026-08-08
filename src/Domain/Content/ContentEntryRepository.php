<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use DateTimeImmutable;

/**
 * Zugriff auf Seiten und Beitraege. Kein SQL, keine PDO-Typen — die
 * Implementierung liegt in src/Infra/Persistence.
 */
interface ContentEntryRepository
{
    public function findById(int $id): ?ContentEntry;

    /**
     * Der Weg der Catch-all-Route: ein Index-Treffer auf content_entries.path.
     */
    public function findByPath(string $path): ?ContentEntry;

    /**
     * Fuer die Kollisionspruefung beim Umbenennen: Gibt es unter demselben
     * Elternteil schon diesen Slug?
     */
    public function findBySlug(ContentType $type, ?int $parentId, string $slug): ?ContentEntry;

    /**
     * Legt an oder schreibt fort — je nachdem, ob der Eintrag eine ID hat.
     *
     * @return int die ID des gespeicherten Eintrags
     */
    public function save(ContentEntry $entry): int;

    public function delete(int $id): void;

    /**
     * Die direkten Kinder einer Seite, nach sort_order.
     *
     * @return list<ContentEntry>
     */
    public function children(?int $parentId): array;

    /**
     * Alle Nachfahren einer Seite — gebraucht, wenn ein Slug weiter oben sich
     * aendert und die Pfade darunter mitwandern.
     *
     * @return list<ContentEntry>
     */
    public function descendants(int $parentId): array;

    /**
     * Veroeffentlichtes, neueste zuerst.
     *
     * @return list<ContentEntry>
     */
    public function published(ContentType $type, int $limit, int $offset = 0): array;

    public function countPublished(ContentType $type): int;

    /**
     * Was der Auftrag content.publish freischalten muss: geplant und faellig.
     *
     * @return list<ContentEntry>
     */
    public function due(DateTimeImmutable $now, int $limit = 100): array;

    /**
     * Die Liste in der Verwaltung.
     *
     * @return list<ContentEntry>
     */
    public function search(?ContentType $type, ?ContentStatus $status, ?string $query, int $limit = 100): array;

    /**
     * Alles Veroeffentlichte fuer Sitemap und Volltextindex.
     *
     * @return iterable<ContentEntry>
     */
    public function allPublished(): iterable;
}
