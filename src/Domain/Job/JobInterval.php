<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Job;

/**
 * Wiederkehrende Abstaende im Zeitplan — in Minuten.
 *
 * Vorher stand im Zeitplan null fuer "bei jedem Lauf". Das ging, solange die
 * Crontab genau stuendlich lief: "jeder Lauf" und "stuendlich" waren dasselbe.
 * Mit dem Auftrag content.publish stimmt das nicht mehr — er soll
 * viertelstuendlich laufen, und dazu muss die Crontab oefter aufrufen. Ohne
 * echten Abstand wuerden die stuendlichen Aufgaben dann viermal je Stunde
 * eingeplant.
 */
enum JobInterval: int
{
    case Viertelstuendlich = 15;
    case Stuendlich = 60;

    public function minutes(): int
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Viertelstuendlich => 'viertelstündlich',
            self::Stuendlich => 'stündlich',
        };
    }
}
