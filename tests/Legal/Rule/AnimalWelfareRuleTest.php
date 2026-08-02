<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Legal\Rule;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Legal\LegalDecision;
use Reptilienmarkt\Legal\Rule\AnimalWelfareRule;
use Reptilienmarkt\Tests\Legal\LegalTestCase;

/**
 * Regel 6 — Mindestabgabealter und -gewicht.
 */
#[CoversClass(AnimalWelfareRule::class)]
final class AnimalWelfareRuleTest extends LegalTestCase
{
    private function rule(bool $requireData = true): AnimalWelfareRule
    {
        return new AnimalWelfareRule($this->clock, $requireData, 'hatch_date', 'weight_g', 'legal.tierschutz_abgabe');
    }

    private function decide(
        ?DateTimeImmutable $hatchDate,
        ?int $weightG,
        ListingType $type = ListingType::Verkauf,
        bool $requireData = true,
    ): LegalDecision {
        return $this->evaluateRule($this->rule($requireData), $this->context(
            species: $this->species(minAbgabeAlterWochen: 8, minAbgabeGewichtG: 25),
            type: $type,
            hatchDate: $hatchDate,
            weightG: $weightG,
        ));
    }

    public function testAusreichendAltUndSchwerWirdFreigegeben(): void
    {
        $decision = $this->decide($this->weeksAgo(10), 30);

        $this->assertNotBlocked($decision);
        $this->assertNoMissingTexts($decision);
    }

    /**
     * Grenzfall: exakt das Mindestalter — 56 Tage sind genau 8 Wochen.
     */
    public function testExaktDasMindestalterIstZulaessig(): void
    {
        $decision = $this->decide($this->daysAgo(56), 30);

        $this->assertNotBlocked($decision);
    }

    public function testEinenTagUnterDemMindestalterBlockiert(): void
    {
        $decision = $this->decide($this->daysAgo(55), 30);

        $this->assertBlockedBecause($decision, 'abgabealter_unterschritten');
    }

    /**
     * Grenzfall: exakt das Mindestgewicht.
     */
    public function testExaktDasMindestgewichtIstZulaessig(): void
    {
        $decision = $this->decide($this->weeksAgo(10), 25);

        $this->assertNotBlocked($decision);
    }

    public function testEinGrammUnterDemMindestgewichtBlockiert(): void
    {
        $decision = $this->decide($this->weeksAgo(10), 24);

        $this->assertBlockedBecause($decision, 'abgabegewicht_unterschritten');
    }

    public function testAlterUndGewichtWerdenBeideGeprueft(): void
    {
        $decision = $this->decide($this->weeksAgo(4), 10);

        self::assertTrue($decision->blocked);
        $codes = array_column(array_map(
            static fn($reason): array => $reason->toArray(),
            $decision->blockingReasons(),
        ), 'code');

        self::assertContains('abgabealter_unterschritten', $codes);
        self::assertContains('abgabegewicht_unterschritten', $codes);
    }

    public function testFehlendesSchlupfdatumBlockiert(): void
    {
        $decision = $this->decide(null, 30);

        $this->assertBlockedBecause($decision, 'schlupfdatum_fehlt');
        self::assertContains('hatch_date', $decision->requiredFields);
    }

    public function testFehlendesGewichtBlockiert(): void
    {
        $decision = $this->decide($this->weeksAgo(10), null);

        $this->assertBlockedBecause($decision, 'gewicht_fehlt');
        self::assertContains('weight_g', $decision->requiredFields);
    }

    public function testOhnePflichtdatenNurPruefungSoweitVorhanden(): void
    {
        $decision = $this->decide(null, null, requireData: false);

        $this->assertNotBlocked($decision);
        self::assertSame([], $decision->requiredFields);
    }

    public function testSchlupfdatumInDerZukunftBlockiert(): void
    {
        $decision = $this->decide((new DateTimeImmutable(self::NOW))->modify('+1 day'), 30);

        $this->assertBlockedBecause($decision, 'schlupfdatum_in_zukunft');
    }

    /**
     * Nachzucht-Vorbestellungen beschreiben Tiere, die es noch nicht gibt —
     * dort darf die Regel nicht blockieren, sondern nur informieren.
     */
    public function testVorbestellungWirdNichtBlockiert(): void
    {
        $decision = $this->decide($this->weeksAgo(1), 5, ListingType::NachzuchtVorbestellung);

        $this->assertNotBlocked($decision);
        self::assertContains('vorbestellung_ausgenommen', $this->codes($decision));
        self::assertCount(1, $decision->notices);
    }

    public function testVorbestellungOhneDatenWirdNichtBlockiert(): void
    {
        $decision = $this->decide(null, null, ListingType::NachzuchtVorbestellung);

        $this->assertNotBlocked($decision);
        self::assertSame([], $decision->requiredFields);
    }

    public function testGreiftNichtBeiGesuch(): void
    {
        $decision = $this->decide(null, null, ListingType::Gesuch);

        self::assertSame([], $decision->reasons);
    }

    public function testGreiftNichtOhneGrenzwerteImArtenstamm(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(
            species: $this->species(),
            hatchDate: $this->weeksAgo(1),
            weightG: 1,
        ));

        $this->assertNotBlocked($decision);
        self::assertSame([], $decision->reasons);
    }

    /**
     * Nur ein Grenzwert gesetzt: das jeweils andere Feld darf nicht verlangt werden.
     */
    public function testNurAltersgrenzeVerlangtNurDasSchlupfdatum(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(
            species: $this->species(minAbgabeAlterWochen: 8),
            hatchDate: $this->weeksAgo(10),
        ));

        $this->assertNotBlocked($decision);
        self::assertSame(['hatch_date'], $decision->requiredFields);
    }

    public function testNurGewichtsgrenzeVerlangtNurDasGewicht(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(
            species: $this->species(minAbgabeGewichtG: 25),
            weightG: 30,
        ));

        $this->assertNotBlocked($decision);
        self::assertSame(['weight_g'], $decision->requiredFields);
    }
}
