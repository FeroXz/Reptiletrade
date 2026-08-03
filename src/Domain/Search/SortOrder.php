<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Search;

enum SortOrder: string
{
    case Neueste = 'neueste';
    case PreisAufsteigend = 'preis_auf';
    case PreisAbsteigend = 'preis_ab';
    case Entfernung = 'entfernung';
    case Relevanz = 'relevanz';

    public function label(): string
    {
        return match ($this) {
            self::Neueste => 'Neueste zuerst',
            self::PreisAufsteigend => 'Preis aufsteigend',
            self::PreisAbsteigend => 'Preis absteigend',
            self::Entfernung => 'Entfernung',
            self::Relevanz => 'Relevanz',
        };
    }

    /**
     * Entfernung braucht einen Mittelpunkt, Relevanz einen Suchbegriff.
     * Ohne die jeweilige Voraussetzung faellt die Sortierung auf "neueste" zurueck.
     */
    public function isAvailable(bool $hasRadius, bool $hasQuery): bool
    {
        return match ($this) {
            self::Entfernung => $hasRadius,
            self::Relevanz => $hasQuery,
            default => true,
        };
    }
}
