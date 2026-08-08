<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use DateTimeImmutable;
use Reptilienmarkt\Domain\Audit\AuditEntry;
use Reptilienmarkt\Domain\Audit\AuditLog;
use Reptilienmarkt\Support\Clock;
use Reptilienmarkt\Support\Slugger;

/**
 * Die schreibende Seite des Redaktionssystems.
 *
 * Alles, was einen Eintrag veraendert, geht hierdurch: Hier wird der Pfad
 * gebaut, gegen die Reservierungsliste geprueft, die Eindeutigkeit
 * sichergestellt, die Kinderpfade nachgezogen — und jede Aenderung in den
 * Audit-Trail geschrieben. Ein Controller, der stattdessen selbst das
 * Repository anspraeche, umginge genau diese Kette.
 */
final readonly class ContentService
{
    public function __construct(
        private ContentEntryRepository $entries,
        private ContentBlockRepository $blocks,
        private AuditLog $audit,
        private Clock $clock,
    ) {}

    /**
     * @throws ContentException
     */
    public function create(
        ContentType $type,
        string $title,
        ?string $slug,
        ?int $parentId,
        int $actorId,
        ?ContentTemplate $template = null,
    ): ContentEntry {
        $title = trim($title);

        if ($title === '') {
            throw new ContentException('Ohne Titel geht es nicht — er steht später in der Überschrift und im Menü.');
        }

        $now = $this->clock->now();
        $slug = $this->slug($slug ?? $title);
        $parentId = $type->allowsParent() ? $parentId : null;

        $entry = new ContentEntry(
            id: null,
            type: $type,
            slug: $slug,
            path: $this->buildPath($type, $slug, $parentId, null, $now),
            title: $title,
            template: $template ?? ContentTemplate::forType($type),
            parentId: $parentId,
            createdAt: $now,
            updatedAt: $now,
            authorId: $actorId,
            updatedBy: $actorId,
        );

        $this->guardPath($entry->path, $type, null);
        $this->guardSlug($type, $parentId, $slug, null);

        $id = $this->entries->save($entry);

        $this->record('content.created', $id, $actorId, [
            'typ' => $type->value,
            'pfad' => $entry->path,
            'titel' => $title,
        ]);

        return $entry->withId($id);
    }

    /**
     * Schreibt den Kopf fort.
     *
     * Aendert sich der Slug, wandert der Pfad — und mit ihm die Pfade aller
     * Unterseiten. Das geschieht in einer Transaktion des Repositories, damit
     * niemand einen Baum sieht, dessen Haelfte schon umgezogen ist.
     *
     * @param array{titel?: string, slug?: string, anriss?: string, meta_titel?: string|null,
     *              meta_beschreibung?: string|null, noindex?: bool, vorlage?: ContentTemplate,
     *              eltern_id?: int|null, reihenfolge?: int} $changes
     *
     * @return list<array{alt: string, neu: string}> die Pfade, die sich geaendert haben
     *
     * @throws ContentException
     */
    public function updateHeader(int $id, array $changes, int $actorId): array
    {
        $entry = $this->require($id);
        $now = $this->clock->now();

        $title = isset($changes['titel']) ? trim($changes['titel']) : $entry->title;

        if ($title === '') {
            throw new ContentException('Ohne Titel geht es nicht — er steht später in der Überschrift und im Menü.');
        }

        $slug = isset($changes['slug']) && trim($changes['slug']) !== ''
            ? $this->slug($changes['slug'])
            : $entry->slug;

        $parentId = \array_key_exists('eltern_id', $changes) ? $changes['eltern_id'] : $entry->parentId;
        $parentId = $entry->type->allowsParent() ? $parentId : null;

        if ($parentId !== null) {
            $this->guardParent($entry, $parentId);
        }

        $path = $this->buildPath($entry->type, $slug, $parentId, $entry->publishedAt ?? $entry->createdAt, $now);

        $this->guardPath($path, $entry->type, $id);
        $this->guardSlug($entry->type, $parentId, $slug, $id);

        $updated = new ContentEntry(
            id: $entry->id,
            type: $entry->type,
            slug: $slug,
            path: $path,
            title: $title,
            status: $entry->status,
            template: $changes['vorlage'] ?? $entry->template,
            parentId: $parentId,
            excerpt: isset($changes['anriss']) ? trim($changes['anriss']) : $entry->excerpt,
            metaTitle: \array_key_exists('meta_titel', $changes) ? $changes['meta_titel'] : $entry->metaTitle,
            metaDescription: \array_key_exists('meta_beschreibung', $changes)
                ? $changes['meta_beschreibung']
                : $entry->metaDescription,
            ogImageId: $entry->ogImageId,
            noindex: $changes['noindex'] ?? $entry->noindex,
            locale: $entry->locale,
            publishedAt: $entry->publishedAt,
            createdAt: $entry->createdAt,
            updatedAt: $now,
            authorId: $entry->authorId,
            updatedBy: $actorId,
            sortOrder: $changes['reihenfolge'] ?? $entry->sortOrder,
        );

        $this->entries->save($updated);

        $moved = $path === $entry->path ? [] : [['alt' => $entry->path, 'neu' => $path]];

        if ($moved !== []) {
            $moved = array_merge($moved, $this->moveDescendants($updated, $now, $actorId));

            $this->record('content.updated', $id, $actorId, [
                'pfad_alt' => $entry->path,
                'pfad_neu' => $path,
                'verschobene_unterseiten' => \count($moved) - 1,
            ]);
        }

        return $moved;
    }

    /**
     * @param list<ContentBlock> $blocks
     */
    public function saveBlocks(int $id, array $blocks, int $actorId): void
    {
        $entry = $this->require($id);

        $this->blocks->replaceAll($id, $blocks);
        $this->entries->save($entry->withTouch($this->clock->now(), $actorId));
    }

    /**
     * Veroeffentlichen — sofort oder zu einem Termin.
     *
     * Ein Termin in der Zukunft ergibt den Status "geplant"; freigeschaltet
     * wird er vom Auftrag content.publish. Nicht beim naechsten Aufruf: Eine
     * Seite, die erst erscheint, wenn zufaellig jemand vorbeikommt, erscheint
     * auf einer leisen Website gar nicht.
     */
    public function publish(int $id, int $actorId, ?DateTimeImmutable $when = null): ContentEntry
    {
        $entry = $this->require($id);
        $now = $this->clock->now();
        $moment = $when ?? $now;

        $scheduled = $moment > $now;
        $status = $scheduled ? ContentStatus::Geplant : ContentStatus::Veroeffentlicht;

        // Beim Beitrag traegt der Pfad das Jahr — mit dem Termin kann es sich
        // aendern. Das geschieht hier und nicht spaeter im Auftrag: Der
        // Redakteur soll die endgueltige Adresse sehen, bevor er sie teilt.
        $path = $entry->type === ContentType::Beitrag
            ? ContentPath::forPost($entry->slug, $moment, $entry->createdAt ?? $now)
            : $entry->path;

        if ($path !== $entry->path) {
            $this->guardPath($path, $entry->type, $id);
        }

        $updated = $entry
            ->withStatus($status, $moment)
            ->withPath($path)
            ->withTouch($now, $actorId);

        $this->entries->save($updated);

        $this->record($scheduled ? 'content.scheduled' : 'content.published', $id, $actorId, [
            'pfad' => $path,
            'termin' => $moment->format(\DATE_ATOM),
        ]);

        return $updated;
    }

    /**
     * Zuruecknehmen. Legt ausdruecklich KEINE Weiterleitung an: Die Seite soll
     * zurueckkommen, und eine 301 auf etwas anderes stuende dem im Weg.
     */
    public function unpublish(int $id, int $actorId): ContentEntry
    {
        $entry = $this->require($id);

        $updated = $entry->withStatus(ContentStatus::Entwurf)->withTouch($this->clock->now(), $actorId);
        $this->entries->save($updated);

        $this->record('content.unpublished', $id, $actorId, ['pfad' => $entry->path]);

        return $updated;
    }

    /**
     * Archivieren: Der Inhalt bleibt, die Adresse antwortet mit 410. Ein
     * bewusst entfernter Inhalt ist etwas anderes als ein Tippfehler in der URL.
     */
    public function archive(int $id, int $actorId): ContentEntry
    {
        $entry = $this->require($id);

        $updated = $entry->withStatus(ContentStatus::Archiviert)->withTouch($this->clock->now(), $actorId);
        $this->entries->save($updated);

        $this->record('content.archived', $id, $actorId, ['pfad' => $entry->path]);

        return $updated;
    }

    /**
     * @throws ContentException
     */
    public function delete(int $id, int $actorId): void
    {
        $entry = $this->require($id);

        if ($this->entries->children($id) !== []) {
            throw new ContentException(
                'Diese Seite hat Unterseiten. Verschiebe oder lösche sie zuerst — sonst zeigen ihre Pfade ins Leere.',
            );
        }

        // Der Audit-Eintrag entsteht vor dem Loeschen: Danach gibt es die
        // Angaben nicht mehr, die ihn brauchbar machen.
        $this->record('content.deleted', $id, $actorId, [
            'typ' => $entry->type->value,
            'pfad' => $entry->path,
            'titel' => $entry->title,
            'status' => $entry->status->value,
        ]);

        $this->entries->delete($id);
    }

    /**
     * @throws ContentException
     */
    public function require(int $id): ContentEntry
    {
        $entry = $this->entries->findById($id);

        if ($entry === null) {
            throw new ContentException('Diesen Inhalt gibt es nicht (mehr).');
        }

        return $entry;
    }

    /**
     * Zieht die Pfade aller Unterseiten nach.
     *
     * @return list<array{alt: string, neu: string}>
     */
    private function moveDescendants(ContentEntry $parent, DateTimeImmutable $now, int $actorId): array
    {
        $moved = [];
        /** @var array<int, string> $paths */
        $paths = [$parent->id ?? 0 => $parent->path];

        // descendants() liefert nach Pfadlaenge sortiert — Eltern also vor
        // ihren Kindern. Damit steht der neue Elternpfad schon fest, wenn das
        // Kind an die Reihe kommt.
        foreach ($this->entries->descendants($parent->id ?? 0) as $child) {
            $base = $paths[$child->parentId ?? 0] ?? null;

            if ($base === null) {
                continue;
            }

            $neu = ContentPath::forPage($child->slug, $base);
            $paths[$child->id ?? 0] = $neu;

            if ($neu === $child->path) {
                continue;
            }

            $this->entries->save($child->withPath($neu)->withTouch($now, $actorId));
            $moved[] = ['alt' => $child->path, 'neu' => $neu];
        }

        return $moved;
    }

    private function buildPath(
        ContentType $type,
        string $slug,
        ?int $parentId,
        ?DateTimeImmutable $published,
        DateTimeImmutable $fallback,
    ): string {
        if ($type === ContentType::Beitrag) {
            return ContentPath::forPost($slug, $published, $fallback);
        }

        $parentPath = null;

        if ($parentId !== null) {
            $parent = $this->entries->findById($parentId);

            if ($parent === null || $parent->type !== ContentType::Seite) {
                throw new ContentException('Die gewählte Elternseite gibt es nicht.');
            }

            $parentPath = $parent->path;
        }

        return ContentPath::forPage($slug, $parentPath);
    }

    private function slug(string $value): string
    {
        $slug = Slugger::slug($value);

        if ($slug === '' || ReservedPaths::isForbiddenSlug($slug)) {
            throw new ContentException(\sprintf('"%s" ergibt keinen brauchbaren Slug.', $value));
        }

        return $slug;
    }

    /**
     * @throws ContentException
     */
    private function guardPath(string $path, ContentType $type, ?int $ownId): void
    {
        ReservedPaths::guard($path, $type);

        $existing = $this->entries->findByPath($path);

        if ($existing !== null && $existing->id !== $ownId) {
            throw new ContentException(\sprintf(
                'Der Pfad "%s" ist bereits vergeben — an "%s".',
                $path,
                $existing->title,
            ));
        }
    }

    /**
     * @throws ContentException
     */
    private function guardSlug(ContentType $type, ?int $parentId, string $slug, ?int $ownId): void
    {
        $existing = $this->entries->findBySlug($type, $parentId, $slug);

        if ($existing !== null && $existing->id !== $ownId) {
            throw new ContentException(\sprintf('Der Slug "%s" ist an dieser Stelle schon vergeben.', $slug));
        }
    }

    /**
     * Eine Seite darf nicht unter sich selbst haengen — der Pfad waere dann
     * nicht mehr berechenbar, und descendants() liefe im Kreis.
     *
     * @throws ContentException
     */
    private function guardParent(ContentEntry $entry, int $parentId): void
    {
        if ($parentId === $entry->id) {
            throw new ContentException('Eine Seite kann nicht ihre eigene Elternseite sein.');
        }

        foreach ($this->entries->descendants($entry->id ?? 0) as $descendant) {
            if ($descendant->id === $parentId) {
                throw new ContentException('Die gewählte Elternseite liegt unterhalb dieser Seite — das ergäbe einen Kreis.');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function record(string $action, int $entryId, int $actorId, array $data): void
    {
        $this->audit->record(new AuditEntry($action, 'content_entry', $entryId, $data, $actorId));
    }
}
