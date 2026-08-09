<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Site;

use DateTimeImmutable;

interface SiteIdentityOverrideRepository
{
    /**
     * Alle Ueberschreibungen auf einmal.
     *
     * Alle statt je Feld: Jede Rechtsseite liest ein Dutzend Angaben, und ein
     * Dutzend Abfragen je Seitenaufruf waere der Preis fuer nichts.
     *
     * @return array<string, string> Feldschluessel => Wert
     */
    public function all(): array;

    /**
     * @return array<string, TextOverride> Feldschluessel => Eintrag mit Aenderungsdatum
     */
    public function withMeta(): array;

    public function set(string $fieldKey, string $value, DateTimeImmutable $moment, ?int $userId): void;

    public function remove(string $fieldKey): void;

    public function count(): int;
}
