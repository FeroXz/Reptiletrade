<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Species\Inheritance;
use Reptilienmarkt\Domain\Species\Morph;
use Reptilienmarkt\Support\Slugger;

/**
 * Uebersetzt den Merkmalskatalog einer Art in Genorte.
 *
 * Der Katalog kennt Merkmale, die Vererbungsrechnung braucht Genorte. Die
 * Zuordnung folgt zwei Angaben, die schon in der Tabelle stehen:
 *
 * - allele_group fasst Merkmale zusammen, die denselben Ort besetzen
 *   (Zero und Witblits bei der Bartagame). Ohne Gruppe bekommt jedes Merkmal
 *   seinen eigenen Ort.
 * - Superformen (Silkback zu Leatherback) sind kein eigener Ort und kein
 *   eigenes Allel, sondern die homozygote Auspraegung des Basisallels. Welche
 *   Merkmale so zusammengehoeren, steht in config/genetik.php — aus dem Namen
 *   laesst es sich nicht ableiten, und Raten waere hier die schlechteste aller
 *   Moeglichkeiten.
 */
final readonly class LocusMap
{
    /**
     * @param array<string, Locus>          $loci
     * @param array<int, LocusPlacement>    $placements Merkmals-ID => Ort im Genom
     */
    private function __construct(
        private array $loci,
        private array $placements,
    ) {}

    /**
     * @param list<Morph>           $morphs
     * @param array<string, string> $superForms Name der Superform => Name des Basismerkmals
     */
    public static function build(array $morphs, array $superForms = []): self
    {
        $byName = [];
        foreach ($morphs as $morph) {
            $byName[$morph->name] = $morph;
        }

        // Superformen zuerst aufloesen: Sie fallen als eigenstaendige Allele
        // weg, bevor die Genorte gebildet werden.
        $superOf = [];
        foreach ($superForms as $superName => $baseName) {
            $super = $byName[$superName] ?? null;
            $base = $byName[$baseName] ?? null;

            if ($super === null || $base === null) {
                continue;
            }

            $superOf[$super->name] = $base;
        }

        /** @var array<string, array{inheritance: Inheritance, morphs: array<string, Morph>, supers: array<string, Morph>}> $groups */
        $groups = [];
        $placements = [];

        foreach ($morphs as $morph) {
            $base = $superOf[$morph->name] ?? null;
            $carrier = $base ?? $morph;
            $locusId = self::locusId($carrier);
            $allele = self::allele($carrier);

            $groups[$locusId] ??= ['inheritance' => $morph->inheritance, 'morphs' => [], 'supers' => []];

            if ($base !== null) {
                $groups[$locusId]['supers'][$allele] = $morph;
            } else {
                $groups[$locusId]['morphs'][$allele] = $morph;
                // Geschlechtsgebundenheit schlaegt jede andere Angabe der
                // Gruppe: Sie entscheidet, ob ueberhaupt zwei Allele vorliegen.
                if ($morph->inheritance === Inheritance::SexLinked) {
                    $groups[$locusId]['inheritance'] = Inheritance::SexLinked;
                }
            }
        }

        $loci = [];
        foreach ($groups as $locusId => $group) {
            $loci[$locusId] = new Locus($locusId, $group['inheritance'], $group['morphs'], $group['supers']);
        }

        foreach ($morphs as $morph) {
            if ($morph->id === null) {
                continue;
            }

            $base = $superOf[$morph->name] ?? null;
            $carrier = $base ?? $morph;
            $locus = $loci[self::locusId($carrier)] ?? null;

            if ($locus === null) {
                continue;
            }

            $placements[$morph->id] = new LocusPlacement($locus, self::allele($carrier), $base !== null);
        }

        return new self($loci, $placements);
    }

    /**
     * @return array<string, Locus>
     */
    public function all(): array
    {
        return $this->loci;
    }

    public function get(string $id): ?Locus
    {
        return $this->loci[$id] ?? null;
    }

    public function placement(int $morphId): ?LocusPlacement
    {
        return $this->placements[$morphId] ?? null;
    }

    /**
     * Das Merkmal hinter einem Allel — fuer die Rueckuebersetzung eines
     * errechneten Genotyps in sichtbare Merkmale.
     */
    public function morphOf(string $locusId, string $allele): ?Morph
    {
        $locus = $this->loci[$locusId] ?? null;

        return $locus?->morph($allele);
    }

    private static function locusId(Morph $morph): string
    {
        return $morph->alleleGroup ?? Slugger::slug($morph->name);
    }

    private static function allele(Morph $morph): string
    {
        return Slugger::slug($morph->name);
    }
}
