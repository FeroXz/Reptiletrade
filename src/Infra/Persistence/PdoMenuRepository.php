<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Persistence;

use Reptilienmarkt\Domain\Content\MenuItem;
use Reptilienmarkt\Domain\Content\MenuRepository;
use Reptilienmarkt\Domain\Content\MenuTargetType;
use Reptilienmarkt\Domain\Content\MenuVisibility;

final readonly class PdoMenuRepository implements MenuRepository
{
    public function __construct(private Database $database) {}

    public function menus(): array
    {
        $menus = [];

        foreach ($this->database->select('SELECT slug, name FROM menus ORDER BY id') as $row) {
            $menus[(string) $row['slug']] = (string) $row['name'];
        }

        return $menus;
    }

    public function menuId(string $slug): ?int
    {
        $id = $this->database->scalar('SELECT id FROM menus WHERE slug = :slug', ['slug' => $slug]);

        return $id === null ? null : (int) $id;
    }

    public function items(string $menuSlug): array
    {
        $items = [];

        foreach ($this->database->select(
            'SELECT i.id, i.menu_id, i.parent_id, i.label, i.target_type, i.target_value, i.sort_order, i.visibility
               FROM menu_items i
               JOIN menus m ON m.id = i.menu_id
              WHERE m.slug = :slug
              ORDER BY i.parent_id NULLS FIRST, i.sort_order ASC, i.id ASC',
            ['slug' => $menuSlug],
        ) as $row) {
            $items[] = new MenuItem(
                id: (int) $row['id'],
                menuId: (int) $row['menu_id'],
                label: (string) $row['label'],
                targetType: MenuTargetType::from((string) $row['target_type']),
                targetValue: (string) $row['target_value'],
                parentId: $row['parent_id'] === null ? null : (int) $row['parent_id'],
                sortOrder: (int) $row['sort_order'],
                visibility: MenuVisibility::from((string) $row['visibility']),
            );
        }

        return $items;
    }

    public function saveItem(MenuItem $item): int
    {
        $parameters = [
            'menu' => $item->menuId,
            'parent' => $item->parentId,
            'label' => $item->label,
            'type' => $item->targetType->value,
            'value' => $item->targetValue,
            'sort' => $item->sortOrder,
            'visibility' => $item->visibility->value,
        ];

        if ($item->id !== null) {
            $this->database->execute(
                'UPDATE menu_items SET menu_id = :menu, parent_id = :parent, label = :label,
                        target_type = :type, target_value = :value, sort_order = :sort, visibility = :visibility
                  WHERE id = :id',
                $parameters + ['id' => $item->id],
            );

            return $item->id;
        }

        $this->database->execute(
            'INSERT INTO menu_items (menu_id, parent_id, label, target_type, target_value, sort_order, visibility)
             VALUES (:menu, :parent, :label, :type, :value, :sort, :visibility)',
            $parameters,
        );

        return $this->database->lastInsertId();
    }

    public function deleteItem(int $id): void
    {
        $this->database->execute('DELETE FROM menu_items WHERE id = :id', ['id' => $id]);
    }

    public function danglingItems(): array
    {
        $dangling = [];

        // Nur Eintraege vom Typ "entry" koennen ins Leere zeigen: Eine feste
        // Route und eine fremde Adresse pruefen sich nicht aus der Datenbank.
        foreach ($this->database->select(
            "SELECT i.id, i.label, i.target_value
               FROM menu_items i
              WHERE i.target_type = 'entry'
                AND NOT EXISTS (
                    SELECT 1 FROM content_entries e WHERE e.id = CAST(i.target_value AS INTEGER)
                )
              ORDER BY i.id",
        ) as $row) {
            $dangling[] = [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'target' => (string) $row['target_value'],
            ];
        }

        return $dangling;
    }
}
