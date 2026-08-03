<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

/**
 * Ein Tarif. Kommt aus der Konfiguration, nicht aus der Datenbank: Preise und
 * Grenzen sind Betreiberentscheidungen, keine Nutzerdaten, und sie gehoeren in
 * die Versionsverwaltung.
 */
final readonly class Plan
{
    /**
     * @param array<string, int|null> $limits null bedeutet unbegrenzt
     * @param list<Feature>           $features
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public Money $price,
        public BillingInterval $interval,
        public array $limits = [],
        public array $features = [],
    ) {}

    public function isFree(): bool
    {
        return $this->price->isZero();
    }

    public function has(Feature $feature): bool
    {
        return \in_array($feature, $this->features, true);
    }

    /**
     * Eine Grenze aus dem Tarif. null bedeutet unbegrenzt — deshalb reicht
     * ein Rueckgabewert nicht, der Aufrufer muss zwischen "kein Eintrag" und
     * "ausdruecklich unbegrenzt" unterscheiden koennen.
     */
    public function limit(string $name, ?int $default = null): ?int
    {
        return \array_key_exists($name, $this->limits) ? $this->limits[$name] : $default;
    }

    public function maxActiveListings(): ?int
    {
        return $this->limit('aktive_anzeigen');
    }

    public function runtimeDays(int $default = 60): int
    {
        return $this->limit('laufzeit_tage', $default) ?? $default;
    }

    public function maxImages(int $default = 12): int
    {
        return $this->limit('bilder_je_anzeige', $default) ?? $default;
    }

    /**
     * Preis je Monat — nur fuer die Vergleichsdarstellung. Ein Jahrestarif
     * wird nicht monatlich abgerechnet, er sieht nur so aus.
     */
    public function monthlyEquivalent(): Money
    {
        return match ($this->interval) {
            BillingInterval::Jaehrlich => new Money((int) round($this->price->cents / 12), $this->price->currency),
            default => $this->price,
        };
    }
}
