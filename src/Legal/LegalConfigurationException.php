<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

use RuntimeException;

/**
 * Fehler im Regelwerk aus config/legal_rules.php. Wird beim Aufbau der Engine
 * geworfen, nicht erst bei der Auswertung — ein Tippfehler in der Konfiguration
 * darf nicht als "Regel greift nicht" durchgehen.
 */
final class LegalConfigurationException extends RuntimeException {}
