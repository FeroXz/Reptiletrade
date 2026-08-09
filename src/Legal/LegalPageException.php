<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

use RuntimeException;

/**
 * Fachlicher Fehler beim Pflegen der Rechtsseiten. Die Meldung ist fuer den
 * Betreiber geschrieben und nennt, was schiefging und was zu tun ist.
 */
final class LegalPageException extends RuntimeException {}
