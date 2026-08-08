<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

use RuntimeException;

/**
 * Fachlicher Fehler im Redaktionssystem. Die Meldung ist fuer den Redakteur
 * geschrieben, nicht fuer das Protokoll: Sie nennt, was schiefging und was zu
 * tun ist.
 */
final class ContentException extends RuntimeException {}
