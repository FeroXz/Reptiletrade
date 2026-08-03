<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Moderation;

enum ReportStatus: string
{
    case Offen = 'offen';
    case InPruefung = 'in_pruefung';
    case Erledigt = 'erledigt';
    case Abgelehnt = 'abgelehnt';

    public function label(): string
    {
        return match ($this) {
            self::Offen => 'Offen',
            self::InPruefung => 'In Prüfung',
            self::Erledigt => 'Erledigt',
            self::Abgelehnt => 'Abgelehnt',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Offen || $this === self::InPruefung;
    }
}
