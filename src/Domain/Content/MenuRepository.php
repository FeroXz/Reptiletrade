<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

interface MenuRepository
{
    /**
     * @return array<string, string> slug => Name
     */
    public function menus(): array;

    public function menuId(string $slug): ?int;

    /**
     * Die Eintraege eines Menues, flach und nach Reihenfolge.
     *
     * @return list<MenuItem>
     */
    public function items(string $menuSlug): array;

    public function saveItem(MenuItem $item): int;

    public function deleteItem(int $id): void;

    /**
     * Menueeintraege, deren Inhalt es nicht mehr gibt — fuer bin/doctor.php.
     *
     * @return list<array{id: int, label: string, target: string}>
     */
    public function danglingItems(): array;
}
