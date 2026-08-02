<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Legal\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Listing\LegalDocType;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Species\BnatschgStatus;
use Reptilienmarkt\Domain\Species\EuAnnex;
use Reptilienmarkt\Legal\LegalDocumentInput;
use Reptilienmarkt\Legal\Rule\AnnexACertificateRule;
use Reptilienmarkt\Tests\Legal\LegalTestCase;

/**
 * Regel 1 — Anhang A.
 */
#[CoversClass(AnnexACertificateRule::class)]
final class AnnexACertificateRuleTest extends LegalTestCase
{
    private function rule(): AnnexACertificateRule
    {
        return new AnnexACertificateRule(
            [EuAnnex::A],
            [BnatschgStatus::Streng],
            ['legal_docs.eu_bescheinigung', 'legal_docs.eu_bescheinigung.reference_number'],
            'legal.anhang_a',
        );
    }

    private function testudo(): \Reptilienmarkt\Domain\Species\Species
    {
        return $this->species(
            scientificName: 'Testudo hermanni',
            euAnnex: EuAnnex::A,
            bnatschgStatus: BnatschgStatus::Streng,
            meldepflicht: true,
            dokuPflicht: true,
        );
    }

    /**
     * @param array<string, LegalDocumentInput> $documents
     */
    private function decide(array $documents = [], ListingType $type = ListingType::Verkauf): \Reptilienmarkt\Legal\LegalDecision
    {
        return $this->evaluateRule($this->rule(), $this->context(
            species: $this->testudo(),
            type: $type,
            documents: $documents,
        ));
    }

    public function testOhneBescheinigungsnummerBlockiert(): void
    {
        $decision = $this->decide();

        $this->assertBlockedBecause($decision, 'eu_bescheinigung_fehlt');
        self::assertContains('legal_docs.eu_bescheinigung.reference_number', $decision->requiredFields);
    }

    public function testMitBescheinigungsnummerLandetInDerPruefQueue(): void
    {
        $decision = $this->decide([
            LegalDocType::EuBescheinigung->value => new LegalDocumentInput(
                LegalDocType::EuBescheinigung,
                referenceNumber: 'DE-BW-2026-000123',
                issuingAuthority: 'Regierungspräsidium Karlsruhe',
                fileUploaded: true,
            ),
        ]);

        $this->assertNotBlocked($decision);
        self::assertTrue($decision->requiresReview, 'Anhang A braucht immer eine Admin-Freigabe.');
        $this->assertNoMissingTexts($decision);
    }

    public function testLeereBescheinigungsnummerZaehltNicht(): void
    {
        $decision = $this->decide([
            LegalDocType::EuBescheinigung->value => new LegalDocumentInput(
                LegalDocType::EuBescheinigung,
                referenceNumber: '   ',
                fileUploaded: true,
            ),
        ]);

        $this->assertBlockedBecause($decision, 'eu_bescheinigung_fehlt');
    }

    /**
     * Grenzfall: Datei hochgeladen, aber keine Nummer eingetragen. Die Nummer
     * ist der Anknuepfungspunkt fuer die Behoerde, also reicht die Datei nicht.
     */
    public function testHochgeladeneDateiOhneNummerReichtNicht(): void
    {
        $decision = $this->decide([
            LegalDocType::EuBescheinigung->value => new LegalDocumentInput(
                LegalDocType::EuBescheinigung,
                fileUploaded: true,
            ),
        ]);

        $this->assertBlockedBecause($decision, 'eu_bescheinigung_fehlt');
    }

    public function testPruefungWirdAuchBeiBlockadeVermerkt(): void
    {
        $decision = $this->decide();

        self::assertTrue($decision->requiresReview);
        self::assertContains('freigabe_noetig', $this->codes($decision));
    }

    public function testGreiftNichtBeiAnhangB(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(
            species: $this->species(euAnnex: EuAnnex::B, bnatschgStatus: BnatschgStatus::Besonders),
        ));

        $this->assertNotBlocked($decision);
        self::assertSame([], $decision->reasons);
    }

    /**
     * Grenzfall: streng geschuetzt, aber ohne EU-Anhang im Datensatz — die Regel
     * muss trotzdem greifen, sonst waere eine Datenluecke eine Sicherheitsluecke.
     */
    public function testGreiftBeiStrengGeschuetztOhneAnhang(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(
            species: $this->species(bnatschgStatus: BnatschgStatus::Streng),
        ));

        $this->assertBlockedBecause($decision, 'eu_bescheinigung_fehlt');
    }

    public function testGreiftNichtBeiGesuch(): void
    {
        $decision = $this->decide(type: ListingType::Gesuch);

        $this->assertNotBlocked($decision);
        self::assertSame([], $decision->reasons);
    }

    public function testGreiftBeiTauschUndAbgabe(): void
    {
        foreach ([ListingType::Tausch, ListingType::Abgabe] as $type) {
            $decision = $this->decide(type: $type);

            $this->assertBlockedBecause($decision, 'eu_bescheinigung_fehlt');
        }
    }

    public function testBegruendungLandetImAuditTrail(): void
    {
        $payload = $this->decide()->auditPayload();

        self::assertTrue($payload['blocked']);
        self::assertTrue($payload['requires_review']);
        self::assertSame('anhang_a', $payload['reasons'][0]['rule']);
        self::assertContains('eu_bescheinigung_fehlt', array_column($payload['reasons'], 'code'));
    }
}
