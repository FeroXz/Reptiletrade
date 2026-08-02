<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal\Rule;

/**
 * Wie streng die Gefahrtierregel in einem Bundesland bzw. Kanton greift.
 */
enum DangerousAnimalMode: string
{
    case Sperre = 'sperre';
    case Warnung = 'warnung';
    case Keine = 'keine';
}
