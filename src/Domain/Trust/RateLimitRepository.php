<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Trust;

use DateTimeImmutable;

interface RateLimitRepository
{
    /**
     * Anzahl der Treffer im Fenster, das bei $since beginnt.
     */
    public function countSince(string $bucket, DateTimeImmutable $since): int;

    public function record(string $bucket, DateTimeImmutable $at): void;

    /**
     * Aeltester Treffer im Fenster — daraus ergibt sich, wann wieder Platz ist.
     */
    public function oldestSince(string $bucket, DateTimeImmutable $since): ?DateTimeImmutable;

    /**
     * Raeumt alles vor $before weg. Laeuft im Betrieb ueber einen Job.
     *
     * @return int Anzahl geloeschter Zeilen
     */
    public function purgeBefore(DateTimeImmutable $before): int;
}
