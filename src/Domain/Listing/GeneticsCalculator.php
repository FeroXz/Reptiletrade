<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

/**
 * Genetik-Auswertung hinter einer Schnittstelle.
 *
 * Die mitgelieferte Umsetzung (MorphStringGenerator) deckt Anzeige-String,
 * Genotyp-Kurzform und die Warnungen ab, die der Anzeigenassistent braucht.
 * Ist im DragonReptiles-CMS ein maechtigeres Genetik-Modul vorhanden, wird es
 * hier angebunden statt neu geschrieben.
 */
interface GeneticsCalculator
{
    /**
     * Anzeigeform, z. B. "Hypo Trans het Zero".
     *
     * @param list<MorphSelection> $selection
     */
    public function morphString(array $selection): string;

    /**
     * Genotyp-Kurzform, z. B. "hypo/hypo trans/trans zero/+".
     *
     * @param list<MorphSelection> $selection
     */
    public function genotype(array $selection): string;

    /**
     * Hinweise fuer den Anbieter: Letalkombinationen, allelische Merkmale,
     * unplausible Auspraegungen.
     *
     * @param list<MorphSelection> $selection
     *
     * @return list<string>
     */
    public function warnings(array $selection): array;
}
