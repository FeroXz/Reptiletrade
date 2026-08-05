<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

/**
 * Wo sitzt ein Merkmal? Der Genort, das Allel — und ob das Merkmal die
 * homozygote Auspraegung (Superform) dieses Allels ist.
 */
final readonly class LocusPlacement
{
    public function __construct(
        public Locus $locus,
        public string $allele,
        public bool $isSuperForm = false,
    ) {}
}
