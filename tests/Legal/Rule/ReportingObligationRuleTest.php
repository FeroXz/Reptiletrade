<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Legal\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Legal\LegalDecision;
use Reptilienmarkt\Legal\Rule\ReportingObligationRule;
use Reptilienmarkt\Tests\Legal\LegalTestCase;

/**
 * Regel 3 — Meldepflicht nach § 7 BArtSchV.
 */
#[CoversClass(ReportingObligationRule::class)]
final class ReportingObligationRuleTest extends LegalTestCase
{
    private function rule(): ReportingObligationRule
    {
        return new ReportingObligationRule(
            $this->clock,
            ['meldung_bestaetigt', 'meldung_datum'],
            'meldung_bestaetigt',
            'meldung_datum',
            'legal.meldepflicht',
        );
    }

    /**
     * @param array<string, scalar|null> $confirmations
     */
    private function decide(array $confirmations, ListingType $type = ListingType::Verkauf): LegalDecision
    {
        return $this->evaluateRule($this->rule(), $this->context(
            species: $this->species(meldepflicht: true),
            type: $type,
            confirmations: $confirmations,
        ));
    }

    public function testOhneBestaetigungBlockiert(): void
    {
        $decision = $this->decide([]);

        $this->assertBlockedBecause($decision, 'meldung_nicht_bestaetigt');
        self::assertSame(['meldung_bestaetigt', 'meldung_datum'], $decision->requiredFields);
        $this->assertNoMissingTexts($decision);
    }

    public function testBestaetigungMitDatumWirdAkzeptiert(): void
    {
        $decision = $this->decide([
            'meldung_bestaetigt' => true,
            'meldung_datum' => '2026-07-01',
        ]);

        $this->assertNotBlocked($decision);
        self::assertContains('meldung_bestaetigt', $this->codes($decision));
    }

    public function testBestaetigungOhneDatumBlockiert(): void
    {
        $decision = $this->decide(['meldung_bestaetigt' => true]);

        $this->assertBlockedBecause($decision, 'meldedatum_fehlt');
    }

    public function testUnleserlichesDatumBlockiert(): void
    {
        $decision = $this->decide([
            'meldung_bestaetigt' => true,
            'meldung_datum' => 'demnaechst',
        ]);

        $this->assertBlockedBecause($decision, 'meldedatum_fehlt');
    }

    public function testDatumInDerZukunftBlockiert(): void
    {
        $decision = $this->decide([
            'meldung_bestaetigt' => true,
            'meldung_datum' => '2026-09-01',
        ]);

        $this->assertBlockedBecause($decision, 'meldedatum_in_zukunft');
    }

    /**
     * Grenzfall: Meldung am selben Tag, aber ein paar Stunden vor "jetzt".
     */
    public function testMeldungAmSelbenTagIstZulaessig(): void
    {
        $decision = $this->decide([
            'meldung_bestaetigt' => true,
            'meldung_datum' => '2026-08-02T09:00:00+00:00',
        ]);

        $this->assertNotBlocked($decision);
    }

    /**
     * @param scalar|null $value
     */
    #[DataProvider('bestaetigungsformen')]
    public function testBestaetigungAkzeptiertUebermittlungsformen(mixed $value, bool $expectedConfirmed): void
    {
        $decision = $this->decide([
            'meldung_bestaetigt' => $value,
            'meldung_datum' => '2026-07-01',
        ]);

        self::assertSame($expectedConfirmed, !$decision->blocked);
    }

    /**
     * @return iterable<string, array{scalar|null, bool}>
     */
    public static function bestaetigungsformen(): iterable
    {
        yield 'boolean true' => [true, true];

        yield 'Checkbox "1"' => ['1', true];

        yield 'Formularwert "ja"' => ['ja', true];

        yield 'Integer 1' => [1, true];

        yield 'boolean false' => [false, false];

        yield 'Checkbox "0"' => ['0', false];

        yield 'leer' => [null, false];
    }

    public function testGreiftNichtOhneMeldepflicht(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(species: $this->species()));

        $this->assertNotBlocked($decision);
        self::assertSame([], $decision->reasons);
    }

    public function testGreiftNichtBeiGesuch(): void
    {
        $decision = $this->decide([], ListingType::Gesuch);

        self::assertSame([], $decision->reasons);
    }
}
