<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Contact;

enum ContactTopic: string
{
    case Frage = 'frage';
    case Anzeige = 'anzeige';
    case Konto = 'konto';
    case Recht = 'recht';
    case Missbrauch = 'missbrauch';
    case Sonstiges = 'sonstiges';

    public function label(): string
    {
        return match ($this) {
            self::Frage => 'Allgemeine Frage',
            self::Anzeige => 'Frage zu einer Anzeige',
            self::Konto => 'Mein Konto (Sperre, Zugang, Löschung)',
            self::Recht => 'Rechtliches, Datenschutz, Auskunft',
            self::Missbrauch => 'Missbrauch melden',
            self::Sonstiges => 'Sonstiges',
        };
    }

    /**
     * Anfragen, die keine Woche liegen bleiben duerfen. Sie stehen im
     * Dashboard oben.
     */
    public function isUrgent(): bool
    {
        return $this === self::Missbrauch || $this === self::Recht;
    }
}
