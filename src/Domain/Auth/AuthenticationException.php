<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Auth;

use RuntimeException;

/**
 * Anmeldung fehlgeschlagen. Die Meldung ist bewusst unspezifisch.
 */
final class AuthenticationException extends RuntimeException {}
