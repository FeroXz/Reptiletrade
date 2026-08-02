<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Species;

/**
 * CITES-Anhang (Washingtoner Artenschutzuebereinkommen).
 */
enum CitesAppendix: string
{
    case I = 'I';
    case II = 'II';
    case III = 'III';

    public function label(): string
    {
        return 'CITES Anhang ' . $this->value;
    }
}
