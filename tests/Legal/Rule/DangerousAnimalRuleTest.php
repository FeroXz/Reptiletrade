<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Legal\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Setting\ArraySettings;
use Reptilienmarkt\Domain\Setting\Settings;
use Reptilienmarkt\Legal\LegalDecision;
use Reptilienmarkt\Legal\NoticeSeverity;
use Reptilienmarkt\Legal\Rule\DangerousAnimalMode;
use Reptilienmarkt\Legal\Rule\DangerousAnimalRule;
use Reptilienmarkt\Tests\Legal\LegalTestCase;

/**
 * Regel 5 — Gefahrtiere.
 *
 * Die Zuordnung Region -> Modus kommt vollstaendig aus der Konfiguration; diese
 * Tests pruefen den Mechanismus, nicht eine bestimmte Landesregelung.
 */
#[CoversClass(DangerousAnimalRule::class)]
final class DangerousAnimalRuleTest extends LegalTestCase
{
    /**
     * @param array<string, array<string, DangerousAnimalMode>> $regions
     */
    private function rule(
        array $regions = [],
        DangerousAnimalMode $default = DangerousAnimalMode::Warnung,
        bool $reviewOnWarning = false,
        ?Settings $settings = null,
    ): DangerousAnimalRule {
        return new DangerousAnimalRule(
            $settings ?? new ArraySettings(['legal.gefahrtier_enforcement' => true]),
            $regions,
            $default,
            $reviewOnWarning,
            'legal.gefahrtier_enforcement',
            'legal.gefahrtier',
        );
    }

    private function decide(DangerousAnimalRule $rule, ?string $admin1 = null, Country $country = Country::De): LegalDecision
    {
        return $this->evaluateRule($rule, $this->context(
            species: $this->species(scientificName: 'Naja kaouthia', gefahrtier: true),
            country: $country,
            admin1: $admin1,
        ));
    }

    public function testStandardmodusIstWarnungOhneBlockade(): void
    {
        $decision = $this->decide($this->rule(), 'Bayern');

        $this->assertNotBlocked($decision);
        self::assertContains('gefahrtier_warnung', $this->codes($decision));
        self::assertCount(1, $decision->notices);
        self::assertSame(NoticeSeverity::Warnung, $decision->notices[0]->severity);
        $this->assertNoMissingTexts($decision);
    }

    public function testKonfigurierteSperreBlockiert(): void
    {
        $rule = $this->rule(['DE' => ['Bayern' => DangerousAnimalMode::Sperre]]);

        $decision = $this->decide($rule, 'Bayern');

        $this->assertBlockedBecause($decision, 'gefahrtier_gesperrt');
        self::assertSame(NoticeSeverity::Kritisch, $decision->notices[0]->severity);
    }

    public function testNichtKonfigurierteRegionFaelltAufStandardZurueck(): void
    {
        $rule = $this->rule(['DE' => ['Bayern' => DangerousAnimalMode::Sperre]]);

        $decision = $this->decide($rule, 'Hamburg');

        $this->assertNotBlocked($decision);
        self::assertContains('gefahrtier_warnung', $this->codes($decision));
    }

    public function testRegionenSindProLandGetrennt(): void
    {
        $rule = $this->rule([
            'DE' => ['Bayern' => DangerousAnimalMode::Sperre],
            'CH' => ['ZH' => DangerousAnimalMode::Keine],
        ]);

        $this->assertBlockedBecause($this->decide($rule, 'Bayern', Country::De), 'gefahrtier_gesperrt');

        $swiss = $this->decide($rule, 'ZH', Country::Ch);
        $this->assertNotBlocked($swiss);
        self::assertContains('keine_gefahrtierverordnung', $this->codes($swiss));
        self::assertSame([], $swiss->notices, 'Ohne Verordnung gibt es keinen Nutzerhinweis.');
    }

    public function testOhneRegionsangabeGiltDerStandard(): void
    {
        $rule = $this->rule(['DE' => ['Bayern' => DangerousAnimalMode::Sperre]], DangerousAnimalMode::Sperre);

        $decision = $this->decide($rule, null);

        $this->assertBlockedBecause($decision, 'gefahrtier_gesperrt');
    }

    public function testWarnungKannPruefungAusloesen(): void
    {
        $decision = $this->decide($this->rule(reviewOnWarning: true), 'Bayern');

        $this->assertNotBlocked($decision);
        self::assertTrue($decision->requiresReview);
    }

    public function testGlobalAbschaltbar(): void
    {
        $rule = $this->rule(
            ['DE' => ['Bayern' => DangerousAnimalMode::Sperre]],
            settings: new ArraySettings(['legal.gefahrtier_enforcement' => false]),
        );

        $decision = $this->decide($rule, 'Bayern');

        $this->assertNotBlocked($decision);
        self::assertSame([], $decision->reasons, 'Abgeschaltet heisst: die Regel laeuft gar nicht.');
    }

    public function testOhneSettingIstDieRegelAktiv(): void
    {
        $rule = $this->rule(
            ['DE' => ['Bayern' => DangerousAnimalMode::Sperre]],
            settings: new ArraySettings(),
        );

        $this->assertBlockedBecause($this->decide($rule, 'Bayern'), 'gefahrtier_gesperrt');
    }

    public function testGreiftNichtBeiUngefaehrlicherArt(): void
    {
        $decision = $this->evaluateRule($this->rule(), $this->context(species: $this->species(), admin1: 'Bayern'));

        $this->assertNotBlocked($decision);
        self::assertSame([], $decision->reasons);
    }

    /**
     * Ein Gesuch nach einem Gefahrtier ist ebenfalls relevant — anders als bei
     * den Nachweisregeln greift Regel 5 auch dort.
     */
    public function testGreiftAuchBeiGesuch(): void
    {
        $decision = $this->evaluateRule($this->rule(['DE' => ['Bayern' => DangerousAnimalMode::Sperre]]), $this->context(
            species: $this->species(gefahrtier: true),
            type: \Reptilienmarkt\Domain\Listing\ListingType::Gesuch,
            admin1: 'Bayern',
        ));

        $this->assertBlockedBecause($decision, 'gefahrtier_gesperrt');
    }
}
