<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Storage;

use RuntimeException;

/**
 * Fehler bei der Bildverarbeitung — die Meldung ist fuer den Nutzer gedacht.
 */
final class ImageException extends RuntimeException {}
