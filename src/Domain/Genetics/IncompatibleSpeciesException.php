<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

/**
 * Zwei Tiere verschiedener Arten. Der Fall bekommt eine eigene Ausnahme, weil
 * er eine eigene Antwort verdient: "Bartagame und Leopardgecko lassen sich
 * nicht verpaaren" ist eine Auskunft, "Fehler bei der Berechnung" nicht.
 */
final class IncompatibleSpeciesException extends GeneticsException {}
