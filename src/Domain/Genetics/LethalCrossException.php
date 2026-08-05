<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

/**
 * Aus dieser Verpaarung kann kein lebensfaehiger Nachkomme hervorgehen. Das ist
 * kein Ergebnis mit Warnung, sondern gar kein Ergebnis — deshalb eine Ausnahme.
 */
final class LethalCrossException extends GeneticsException {}
