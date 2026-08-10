<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support\Demo;

use RuntimeException;

/**
 * Fehlende Voraussetzung fuer Beispieldaten — etwa ein leerer Artenstamm.
 */
final class DemoDataException extends RuntimeException
{
    public static function emptySpecies(): self
    {
        return new self('Kein Artenstamm vorhanden — bitte zuerst php bin/seed.php ausfuehren.');
    }

    public static function emptyPostalCodes(): self
    {
        return new self('Keine Postleitzahlen vorhanden — bitte zuerst php bin/seed.php ausfuehren.');
    }
}
