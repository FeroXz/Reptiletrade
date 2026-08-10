<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support\Demo;

/**
 * Die Bausteine, aus denen eine Beispielanzeige zusammengesetzt wird. Einmal
 * gelesen, dann fuer jeden Stapel wiederverwendet — bei 50 000 Anzeigen macht
 * ein erneutes Lesen je Stapel den Unterschied zwischen Sekunden und Minuten.
 *
 * @internal
 */
final readonly class DemoCatalog
{
    /**
     * @param list<array{id: int, common_name: string, min_gewicht: int|null}>        $species
     * @param array<int, list<int>>                                                   $morphs      Merkmal-IDs je Art
     * @param list<array{country: string, postal_code: string, lat: float, lng: float}> $places
     * @param list<int>                                                               $users
     * @param list<string>                                                            $mediaPaths  Pfade unterhalb von STORAGE_PUBLIC
     */
    public function __construct(
        public array $species,
        public array $morphs,
        public array $places,
        public array $users,
        public array $mediaPaths,
    ) {}
}
