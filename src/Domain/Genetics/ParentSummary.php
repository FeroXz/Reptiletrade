<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Listing\Sex;

/**
 * Das Elterntier, wie es im Bericht steht.
 *
 * Ein gespeicherter Bericht muss sich lesen lassen, auch wenn die Anzeige
 * geloescht, das Tier verkauft oder der Merkmalskatalog inzwischen geaendert
 * wurde. Deshalb steht hier eine Abschrift und kein Verweis: Ein Beleg, der
 * sich rueckwirkend aendert, ist kein Beleg.
 */
final readonly class ParentSummary
{
    /**
     * @param array<string, list<string>> $genotype
     */
    public function __construct(
        public string $label,
        public Sex $sex,
        public string $morphString,
        public string $genotypeString,
        public array $genotype,
        public ?int $listingId = null,
    ) {}

    /**
     * @return array{bezeichnung: string, geschlecht: string, morph: string, genotyp_kurz: string, genotyp: array<string, list<string>>, anzeige_id: ?int}
     */
    public function toArray(): array
    {
        return [
            'bezeichnung' => $this->label,
            'geschlecht' => $this->sex->value,
            'morph' => $this->morphString,
            'genotyp_kurz' => $this->genotypeString,
            'genotyp' => $this->genotype,
            'anzeige_id' => $this->listingId,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $genotype = [];
        $stored = $data['genotyp'] ?? [];

        if (\is_array($stored)) {
            foreach ($stored as $locus => $alleles) {
                if (!\is_string($locus) || !\is_array($alleles)) {
                    continue;
                }

                $list = [];
                foreach ($alleles as $allele) {
                    if (\is_string($allele)) {
                        $list[] = $allele;
                    }
                }

                $genotype[$locus] = $list;
            }
        }

        $listingId = $data['anzeige_id'] ?? null;

        return new self(
            \is_string($data['bezeichnung'] ?? null) ? $data['bezeichnung'] : '',
            Sex::tryFrom(\is_string($data['geschlecht'] ?? null) ? $data['geschlecht'] : '') ?? Sex::Unbekannt,
            \is_string($data['morph'] ?? null) ? $data['morph'] : '',
            \is_string($data['genotyp_kurz'] ?? null) ? $data['genotyp_kurz'] : '',
            $genotype,
            \is_int($listingId) ? $listingId : null,
        );
    }
}
