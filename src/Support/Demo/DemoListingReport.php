<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support\Demo;

/**
 * Ergebnis eines Laufs — angelegte bzw. entfernte Zeilen und die Dauer.
 */
final readonly class DemoListingReport
{
    public function __construct(
        public int $users,
        public int $listings,
        public int $morphs,
        public int $media,
        public int $indexed,
        public float $seconds,
    ) {}
}
