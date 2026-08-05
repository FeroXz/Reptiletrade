<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use RuntimeException;

/**
 * Fehler der Vererbungsrechnung. Die Unterklassen unterscheiden die Faelle,
 * die der Aufrufer verschieden beantworten muss.
 */
class GeneticsException extends RuntimeException {}
