<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Legal\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Species\BnatschgStatus;
use Reptilienmarkt\Domain\Species\EuAnnex;
use Reptilienmarkt\Legal\LegalDecision;
use Reptilienmarkt\Legal\Rule\MarkingRule;
use Reptilienmarkt\Tests\Legal\LegalTestCase;

/**
 * Regel 4 — Kennzeichnung bei Landschildkroeten.
 */
#[CoversClass(MarkingRule::class)]
final class MarkingRuleTest extends LegalTestCase
{
    private function rule(): MarkingRule
    {
        return new MarkingRule(
            ['Testudo'],
            ['transponder', 'fotodokumentation'],
            ['transponder'],
            'kennzeichnung_art',
            'kennzeichnung_nummer',
            'legal.kennzeichnung',
        );
    }

    /**
     * @param array<string, scalar|null> $confirmations
     */
    private function decide(
        array $confirmations,
        string $scientificName = 'Testudo hermanni',
        ListingType $type = ListingType::Verkauf,
    ): LegalDecision {
        return $this->evaluateRule($this->rule(), $this->context(
            species: $this->species(
                scientificName: $scientificName,
                euAnnex: EuAnnex::A,
                bnatschgStatus: BnatschgStatus::Streng,
            ),
            type: $type,
            confirmations: $confirmations,
        ));
    }

    public function testOhneAngabeBlockiert(): void
    {
        $decision = $this->decide([]);

        $this->assertBlockedBecause($decision, 'kennzeichnung_fehlt');
        self::assertContains('kennzeichnung_art', $decision->requiredFields);
        $this->assertNoMissingTexts($decision);
    }

    public function testFotodokumentationReichtOhneNummer(): void
    {
        $decision = $this->decide(['kennzeichnung_art' => 'fotodokumentation']);

        $this->assertNotBlocked($decision);
        self::assertNotContains('kennzeichnung_nummer', $decision->requiredFields);
    }

    public function testTransponderVerlangtChipnummer(): void
    {
        $decision = $this->decide(['kennzeichnung_art' => 'transponder']);

        $this->assertBlockedBecause($decision, 'kennzeichnungsnummer_fehlt');
        self::assertContains('kennzeichnung_nummer', $decision->requiredFields);
    }

    public function testTransponderMitChipnummerWirdAkzeptiert(): void
    {
        $decision = $this->decide([
            'kennzeichnung_art' => 'transponder',
            'kennzeichnung_nummer' => '276098106543210',
        ]);

        $this->assertNotBlocked($decision);
        self::assertContains('kennzeichnung_angegeben', $this->codes($decision));
    }

    public function testUnbekannteKennzeichnungsartBlockiert(): void
    {
        $decision = $this->decide(['kennzeichnung_art' => 'tätowierung']);

        $this->assertBlockedBecause($decision, 'kennzeichnung_unbekannt');
    }

    /**
     * Grenzfall: nur Leerzeichen im Feld zaehlt als leer.
     */
    public function testLeerzeichenZaehltAlsFehlend(): void
    {
        $decision = $this->decide(['kennzeichnung_art' => '   ']);

        $this->assertBlockedBecause($decision, 'kennzeichnung_fehlt');
    }

    public function testLeereChipnummerZaehltAlsFehlend(): void
    {
        $decision = $this->decide([
            'kennzeichnung_art' => 'transponder',
            'kennzeichnung_nummer' => '  ',
        ]);

        $this->assertBlockedBecause($decision, 'kennzeichnungsnummer_fehlt');
    }

    public function testGiltFuerAlleTestudoArten(): void
    {
        foreach (['Testudo graeca', 'Testudo marginata', 'Testudo horsfieldii'] as $name) {
            $decision = $this->decide([], $name);

            $this->assertBlockedBecause($decision, 'kennzeichnung_fehlt');
        }
    }

    /**
     * Andere Schildkroetengattungen sind nicht konfiguriert — die Regel darf
     * dort nicht ungefragt greifen.
     */
    public function testGreiftNichtBeiAndererGattung(): void
    {
        $decision = $this->decide([], 'Centrochelys sulcata');

        $this->assertNotBlocked($decision);
        self::assertSame([], $decision->reasons);
    }

    public function testGreiftNichtBeiGesuch(): void
    {
        $decision = $this->decide([], type: ListingType::Gesuch);

        self::assertSame([], $decision->reasons);
    }
}
