<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Species;

/**
 * Anhang der EG-VO 338/97. Anhang A zieht die Vermarktungsgenehmigungspflicht nach sich.
 */
enum EuAnnex: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';

    public function label(): string
    {
        return 'EU-Anhang ' . $this->value;
    }

    /**
     * Anhang A: Vermarktung nur mit Bescheinigung nach Art. 8 Abs. 3 EG-VO 338/97.
     */
    public function requiresMarketingCertificate(): bool
    {
        return $this === self::A;
    }
}
