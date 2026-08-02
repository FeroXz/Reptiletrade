<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Species;

enum CareLevel: string
{
    case Einsteiger = 'einsteiger';
    case Fortgeschritten = 'fortgeschritten';
    case Experte = 'experte';

    public function label(): string
    {
        return match ($this) {
            self::Einsteiger => 'Einsteiger',
            self::Fortgeschritten => 'Fortgeschritten',
            self::Experte => 'Experte',
        };
    }
}
