<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Trust;

use Reptilienmarkt\Support\Clock;

/**
 * Rate-Limits ueber ein gleitendes Fenster.
 *
 * Die uebliche Zaehlervariante mit festem Fenster laesst an der Fenstergrenze
 * die doppelte Menge durch: zwanzig Versuche kurz vor dem Wechsel, zwanzig
 * kurz danach. Hier wird jeder Versuch mit Zeitstempel abgelegt und im
 * Rueckblick gezaehlt — etwas teurer, dafuer ohne diese Luecke.
 */
final readonly class RateLimiter
{
    /**
     * @param array<string, RateLimit> $limits
     */
    public function __construct(
        private RateLimitRepository $repository,
        private Clock $clock,
        private array $limits = [],
    ) {}

    /**
     * Prueft und zaehlt in einem Schritt. Wird die Grenze ueberschritten, ist
     * der Versuch nicht gezaehlt — sonst verlaengerte jeder abgewiesene
     * Versuch die Sperre.
     */
    public function attempt(string $name, string $identifier): RateLimitDecision
    {
        $limit = $this->limits[$name] ?? null;

        if ($limit === null) {
            // Ein unbekannter Name ist ein Konfigurationsfehler, kein Freibrief.
            throw new RateLimitConfigurationException(\sprintf('Unbekanntes Rate-Limit "%s".', $name));
        }

        $now = $this->clock->now();
        $since = $now->modify(\sprintf('-%d seconds', $limit->windowSeconds));
        $bucket = self::bucket($name, $identifier);

        $used = $this->repository->countSince($bucket, $since);

        if ($used >= $limit->limit) {
            $oldest = $this->repository->oldestSince($bucket, $since);
            $freeAt = $oldest?->modify(\sprintf('+%d seconds', $limit->windowSeconds)) ?? $now;

            return new RateLimitDecision(false, 0, $limit, $freeAt);
        }

        $this->repository->record($bucket, $now);

        return new RateLimitDecision(true, $limit->limit - $used - 1, $limit, null);
    }

    /**
     * Nur nachsehen, ohne zu zaehlen.
     */
    public function remaining(string $name, string $identifier): int
    {
        $limit = $this->limits[$name] ?? null;

        if ($limit === null) {
            throw new RateLimitConfigurationException(\sprintf('Unbekanntes Rate-Limit "%s".', $name));
        }

        $since = $this->clock->now()->modify(\sprintf('-%d seconds', $limit->windowSeconds));

        return max(0, $limit->limit - $this->repository->countSince(self::bucket($name, $identifier), $since));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->limits);
    }

    private static function bucket(string $name, string $identifier): string
    {
        return $name . ':' . $identifier;
    }
}
