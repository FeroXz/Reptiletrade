<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Breeding;

enum AnnouncementStatus: string
{
    case Entwurf = 'entwurf';
    case Veroeffentlicht = 'veroeffentlicht';
    case Erledigt = 'erledigt';
    case Zurueckgezogen = 'zurueckgezogen';

    public function label(): string
    {
        return match ($this) {
            self::Entwurf => 'Entwurf',
            self::Veroeffentlicht => 'Veröffentlicht',
            self::Erledigt => 'Geschlüpft',
            self::Zurueckgezogen => 'Zurückgezogen',
        };
    }

    public function isPublic(): bool
    {
        return $this === self::Veroeffentlicht;
    }
}
