<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Site;

use RuntimeException;

/**
 * Eine abgelehnte Textaenderung. Die Meldung geht unveraendert an die
 * Verwaltung — sie sagt, was am Text nicht stimmt.
 */
final class TextException extends RuntimeException {}
