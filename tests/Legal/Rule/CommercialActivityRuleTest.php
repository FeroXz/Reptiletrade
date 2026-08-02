<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Legal\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Legal\LegalDecision;
use Reptilienmarkt\Legal\Rule\CommercialActivityRule;
use Reptilienmarkt\Legal\SellerProfile;
use Reptilienmarkt\Tests\Legal\LegalTestCase;

/**
 * Regel 8 — Gewerblichkeit.
 */
#[CoversClass(CommercialActivityRule::class)]
final class CommercialActivityRuleTest extends LegalTestCase
{
    private function rule(int $listings = 10, int $sales = 25): CommercialActivityRule
    {
        return new CommercialActivityRule($listings, $sales, ['erlaubnis_11_number', 'imprint'], 'legal.gewerblichkeit');
    }

    private function decide(SellerProfile $seller): LegalDecision
    {
        return $this->evaluateRule($this->rule(), $this->context(seller: $seller));
    }

    public function testPrivatverkaeuferUnterhalbDerSchwelleBleibtUnberuehrt(): void
    {
        $decision = $this->decide(new SellerProfile(activeListingCount: 3, salesLastTwelveMonths: 4));

        $this->assertNotBlocked($decision);
        self::assertSame([], $decision->reasons);
        self::assertSame([], $decision->notices);
    }

    /**
     * Grenzfall: genau auf der Schwelle greift die Regel bereits.
     */
    public function testAnzeigenschwelleGenauErreichtLoestAus(): void
    {
        $decision = $this->decide(new SellerProfile(activeListingCount: 10));

        $this->assertNotBlocked($decision);
        self::assertTrue($decision->requiresReview);
        self::assertContains('gewerblichkeitsschwelle_ueberschritten', $this->codes($decision));
        $this->assertNoMissingTexts($decision);
    }

    public function testEineAnzeigeUnterDerSchwelleLoestNichtAus(): void
    {
        $decision = $this->decide(new SellerProfile(activeListingCount: 9));

        self::assertSame([], $decision->reasons);
    }

    public function testVerkaufsschwelleLoestEbenfallsAus(): void
    {
        $decision = $this->decide(new SellerProfile(activeListingCount: 1, salesLastTwelveMonths: 25));

        self::assertTrue($decision->requiresReview);
    }

    public function testSchwellenueberschreitungBlockiertNicht(): void
    {
        $decision = $this->decide(new SellerProfile(activeListingCount: 50, salesLastTwelveMonths: 90));

        $this->assertNotBlocked($decision);
        self::assertContains('erlaubnis_11_number', $decision->requiredFields);
        self::assertContains('imprint', $decision->requiredFields);
    }

    public function testGewerblicherAnbieterOhneErlaubnisnummerBlockiert(): void
    {
        $decision = $this->decide(new SellerProfile(isCommercial: true, hasImprint: true));

        $this->assertBlockedBecause($decision, 'erlaubnis_11_fehlt');
    }

    public function testGewerblicherAnbieterOhneImpressumBlockiert(): void
    {
        $decision = $this->decide(new SellerProfile(
            isCommercial: true,
            erlaubnis11Number: 'VET-2024-8871',
        ));

        $this->assertBlockedBecause($decision, 'impressum_fehlt');
    }

    public function testVollstaendigerGewerblicherAnbieterWirdFreigegeben(): void
    {
        $decision = $this->decide(new SellerProfile(
            activeListingCount: 42,
            salesLastTwelveMonths: 120,
            isCommercial: true,
            erlaubnis11Number: 'VET-2024-8871',
            hasImprint: true,
        ));

        $this->assertNotBlocked($decision);
        self::assertFalse($decision->requiresReview, 'Ein bereits gefuehrter Gewerblicher braucht keine erneute Pruefung.');
        self::assertContains('gewerblicher_anbieter', $this->codes($decision));
    }

    public function testLeereErlaubnisnummerZaehltNicht(): void
    {
        $decision = $this->decide(new SellerProfile(
            isCommercial: true,
            erlaubnis11Number: '   ',
            hasImprint: true,
        ));

        $this->assertBlockedBecause($decision, 'erlaubnis_11_fehlt');
    }

    public function testSchwellwerteSindKonfigurierbar(): void
    {
        $rule = $this->rule(listings: 3, sales: 5);

        $decision = $this->evaluateRule($rule, $this->context(seller: new SellerProfile(activeListingCount: 3)));

        self::assertTrue($decision->requiresReview);
    }

    /**
     * Auch ein Gesuch eines gewerblichen Anbieters braucht Impressum und
     * Erlaubnisnummer — anders als die Nachweisregeln haengt Regel 8 nicht am Tier.
     */
    public function testGiltAuchFuerGesuche(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(
            type: \Reptilienmarkt\Domain\Listing\ListingType::Gesuch,
            seller: new SellerProfile(isCommercial: true),
        ));

        $this->assertBlockedBecause($decision, 'erlaubnis_11_fehlt');
    }
}
