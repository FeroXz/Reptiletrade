<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Legal\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Listing\Handover;
use Reptilienmarkt\Legal\NoticeSeverity;
use Reptilienmarkt\Legal\Rule\ShippingRule;
use Reptilienmarkt\Tests\Legal\LegalTestCase;

/**
 * Regel 7 — Versand.
 */
#[CoversClass(ShippingRule::class)]
final class ShippingRuleTest extends LegalTestCase
{
    /**
     * @param list<Country> $allowed
     */
    private function rule(array $allowed = [Country::De, Country::At, Country::Ch]): ShippingRule
    {
        return new ShippingRule($allowed, 'legal.tiertransport');
    }

    public function testTiertransportZeigtDenFestenHinweis(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(handover: Handover::Tiertransport));

        $this->assertNotBlocked($decision);
        self::assertCount(1, $decision->notices);
        self::assertSame('legal.tiertransport', $decision->notices[0]->key);
        self::assertSame(NoticeSeverity::Warnung, $decision->notices[0]->severity);
        self::assertStringContainsString('Paketdienste', $decision->notices[0]->body);
        $this->assertNoMissingTexts($decision);
    }

    public function testNichtZugelassenesLandBlockiert(): void
    {
        $decision = $this->evaluateRule(
            $this->rule([Country::De]),
            $this->context(country: Country::Ch, handover: Handover::Tiertransport),
        );

        $this->assertBlockedBecause($decision, 'tiertransport_nicht_zulaessig');
    }

    public function testZugelassenesLandWirdVermerkt(): void
    {
        $decision = $this->evaluateRule(
            $this->rule([Country::De, Country::At]),
            $this->context(country: Country::At, handover: Handover::Tiertransport),
        );

        $this->assertNotBlocked($decision);
        self::assertContains('tiertransport_zulaessig', $this->codes($decision));
    }

    public function testLeereLaenderlisteSperrtUeberall(): void
    {
        $decision = $this->evaluateRule($this->rule([]), $this->context(handover: Handover::Tiertransport));

        $this->assertBlockedBecause($decision, 'tiertransport_nicht_zulaessig');
    }

    public function testAbholungLoestDieRegelNichtAus(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(handover: Handover::Abholung));

        $this->assertNotBlocked($decision);
        self::assertSame([], $decision->reasons);
        self::assertSame([], $decision->notices);
    }

    public function testUebergabeAufBoerseLoestDieRegelNichtAus(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(handover: Handover::UebergabeBoerse));

        self::assertSame([], $decision->reasons);
    }
}
