<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\Sex;

/**
 * Welches Geschlecht ist heterogametisch? Daran haengt die gesamte Rechnung bei
 * geschlechtsgebundenen Merkmalen.
 *
 * Bei Reptilien mit genetischer Geschlechtsbestimmung ist das ZW-System der
 * haeufigere Fall (Weibchen ZW, Maennchen ZZ) — anders als bei Saeugern. Ein
 * Z-gebundenes Merkmal zeigt sich beim Weibchen deshalb schon mit einer
 * einzigen Anlage, waehrend das Maennchen Traeger sein kann, ohne es zu zeigen.
 * Bei Arten mit temperaturabhaengiger Geschlechtsbestimmung gibt es keine
 * Geschlechtschromosomen — dort ist "keines" die richtige Antwort, und
 * geschlechtsgebundene Merkmale ergeben keinen Sinn.
 */
enum SexSystem: string
{
    case Zw = 'zw';
    case Xy = 'xy';
    case Keines = 'keines';

    public function label(): string
    {
        return match ($this) {
            self::Zw => 'ZW (Weibchen heterogametisch)',
            self::Xy => 'XY (Männchen heterogametisch)',
            self::Keines => 'keine Geschlechtschromosomen',
        };
    }

    /**
     * Das Geschlecht mit nur einem Geschlechtschromosom, das Allele traegt —
     * und damit hemizygot ist.
     */
    public function hemizygousSex(): ?Sex
    {
        return match ($this) {
            self::Zw => Sex::Weiblich,
            self::Xy => Sex::Maennlich,
            self::Keines => null,
        };
    }

    public function isHemizygous(Sex $sex): bool
    {
        return $this->hemizygousSex() === $sex;
    }

    public function supportsSexLinkage(): bool
    {
        return $this !== self::Keines;
    }
}
