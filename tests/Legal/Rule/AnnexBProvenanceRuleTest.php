<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Legal\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Listing\LegalDocType;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Species\BnatschgStatus;
use Reptilienmarkt\Domain\Species\EuAnnex;
use Reptilienmarkt\Legal\LegalDocumentInput;
use Reptilienmarkt\Legal\Rule\AnnexBProvenanceRule;
use Reptilienmarkt\Tests\Legal\LegalTestCase;

/**
 * Regel 2 — Anhang B.
 */
#[CoversClass(AnnexBProvenanceRule::class)]
final class AnnexBProvenanceRuleTest extends LegalTestCase
{
    private function rule(bool $blockWhenMissing = true, bool $includeDokuPflicht = true): AnnexBProvenanceRule
    {
        return new AnnexBProvenanceRule(
            [EuAnnex::B],
            [EuAnnex::A],
            $includeDokuPflicht,
            $blockWhenMissing,
            ['legal_docs.herkunftsnachweis'],
            'legal.anhang_b',
        );
    }

    private function herkunftsnachweis(): LegalDocumentInput
    {
        return new LegalDocumentInput(LegalDocType::Herkunftsnachweis, fileUploaded: true);
    }

    public function testOhneHerkunftsnachweisBlockiert(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(
            species: $this->species(euAnnex: EuAnnex::B, bnatschgStatus: BnatschgStatus::Besonders, dokuPflicht: true),
        ));

        $this->assertBlockedBecause($decision, 'herkunftsnachweis_fehlt');
        self::assertContains('legal_docs.herkunftsnachweis', $decision->requiredFields);
        $this->assertNoMissingTexts($decision);
    }

    public function testMitHerkunftsnachweisFreigegeben(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(
            species: $this->species(euAnnex: EuAnnex::B, dokuPflicht: true),
            documents: [LegalDocType::Herkunftsnachweis->value => $this->herkunftsnachweis()],
        ));

        $this->assertNotBlocked($decision);
        self::assertFalse($decision->requiresReview, 'Anhang B braucht keine Admin-Freigabe.');
    }

    /**
     * Eine Referenznummer ohne Datei reicht ebenfalls — Herkunftsnachweise sind
     * oft formlos und liegen nicht immer digital vor.
     */
    public function testReferenznummerOhneDateiReicht(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(
            species: $this->species(euAnnex: EuAnnex::B),
            documents: [
                LegalDocType::Herkunftsnachweis->value => new LegalDocumentInput(
                    LegalDocType::Herkunftsnachweis,
                    referenceNumber: 'NZ 2025/17',
                ),
            ],
        ));

        $this->assertNotBlocked($decision);
    }

    public function testAnhangAIstAusgenommen(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(
            species: $this->species(euAnnex: EuAnnex::A, bnatschgStatus: BnatschgStatus::Streng, dokuPflicht: true),
        ));

        $this->assertNotBlocked($decision);
        self::assertSame([], $decision->reasons, 'Bei Anhang A greift Regel 1, nicht Regel 2.');
    }

    /**
     * Grenzfall: Art ohne EU-Anhang, aber mit gesetzter Dokumentationspflicht.
     */
    public function testDokumentationspflichtOhneAnhangGreift(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(
            species: $this->species(dokuPflicht: true),
        ));

        $this->assertBlockedBecause($decision, 'herkunftsnachweis_fehlt');
    }

    public function testDokumentationspflichtKannAbgeschaltetWerden(): void
    {
        $decision = $this->evaluateRule($this->rule(includeDokuPflicht: false), $this->context(
            species: $this->species(dokuPflicht: true),
        ));

        $this->assertNotBlocked($decision);
        self::assertSame([], $decision->reasons);
    }

    public function testOhneBlockadeNurVermerk(): void
    {
        $decision = $this->evaluateRule($this->rule(blockWhenMissing: false), $this->context(
            species: $this->species(euAnnex: EuAnnex::B),
        ));

        $this->assertNotBlocked($decision);
        self::assertContains('herkunftsnachweis_fehlt', $this->codes($decision));
        self::assertContains('legal_docs.herkunftsnachweis', $decision->requiredFields);
    }

    public function testGreiftNichtBeiNichtGeschuetzterArt(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(species: $this->species()));

        $this->assertNotBlocked($decision);
        self::assertSame([], $decision->reasons);
    }

    public function testGreiftNichtBeiGesuch(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(
            species: $this->species(euAnnex: EuAnnex::B),
            type: ListingType::Gesuch,
        ));

        self::assertSame([], $decision->reasons);
    }
}
