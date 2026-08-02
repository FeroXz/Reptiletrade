<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

enum Sex: string
{
    case Maennlich = 'm';
    case Weiblich = 'w';
    case Unbekannt = 'unbekannt';

    public function label(): string
    {
        return match ($this) {
            self::Maennlich => 'männlich',
            self::Weiblich => 'weiblich',
            self::Unbekannt => 'unbekannt',
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::Maennlich => '1.0',
            self::Weiblich => '0.1',
            self::Unbekannt => '0.0.1',
        };
    }
}
