<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Legal;

use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Listing\Handover;
use Reptilienmarkt\Domain\Listing\LegalDocType;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Setting\ArraySettings;
use Reptilienmarkt\Domain\Species\BnatschgStatus;
use Reptilienmarkt\Domain\Species\EuAnnex;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Legal\LegalContext;
use Reptilienmarkt\Legal\LegalDecision;
use Reptilienmarkt\Legal\LegalDocumentInput;
use Reptilienmarkt\Legal\LegalGuard;
use Reptilienmarkt\Legal\LegalRuleFactory;
use Reptilienmarkt\Legal\LegalTextResolver;
use Reptilienmarkt\Legal\SellerProfile;

/**
 * Zusammenspiel aller Regeln gegen das echte Regelwerk aus config/legal_rules.php.
 */
#[CoversClass(LegalGuard::class)]
#[CoversClass(LegalRuleFactory::class)]
final class LegalGuardTest extends LegalTestCase
{
    private function guard(): LegalGuard
    {
        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 2) . '/config/legal_rules.php';

        $factory = new LegalRuleFactory(
            $this->clock,
            new ArraySettings(['legal.gefahrtier_enforcement' => true]),
        );

        return new LegalGuard($factory->fromConfig($config), new LegalTextResolver($this->texts));
    }

    private function testudoHermanni(): Species
    {
        return $this->species(
            scientificName: 'Testudo hermanni',
            euAnnex: EuAnnex::A,
            bnatschgStatus: BnatschgStatus::Streng,
            meldepflicht: true,
            dokuPflicht: true,
            minAbgabeAlterWochen: 12,
            minAbgabeGewichtG: 20,
        );
    }

    private function bartagame(): Species
    {
        return $this->species(minAbgabeAlterWochen: 8, minAbgabeGewichtG: 25);
    }

    /**
     * @param array<string, scalar|null> $extraConfirmations
     */
    private function vollstaendigeSchildkroete(array $extraConfirmations = []): LegalContext
    {
        return $this->context(
            species: $this->testudoHermanni(),
            hatchDate: $this->weeksAgo(20),
            weightG: 60,
            documents: [
                LegalDocType::EuBescheinigung->value => new LegalDocumentInput(
                    LegalDocType::EuBescheinigung,
                    referenceNumber: 'DE-BW-2026-000123',
                    issuingAuthority: 'Regierungspräsidium Karlsruhe',
                    fileUploaded: true,
                ),
            ],
            confirmations: [
                'meldung_bestaetigt' => true,
                'meldung_datum' => '2026-06-15',
                'kennzeichnung_art' => 'transponder',
                'kennzeichnung_nummer' => '276098106543210',
            ] + $extraConfirmations,
        );
    }

    public function testRegelnLaufenInDerReihenfolgeDerKonfiguration(): void
    {
        self::assertSame(
            ['anhang_a', 'anhang_b', 'meldepflicht', 'kennzeichnung', 'gefahrtier', 'tierschutz', 'versand', 'gewerblichkeit'],
            $this->guard()->ruleKeys(),
        );
    }

    /**
     * Akzeptanzkriterium: Anhang-A-Art ohne Bescheinigungsnummer wird blockiert.
     */
    public function testAnhangAOhneBescheinigungsnummerWirdBlockiert(): void
    {
        $decision = $this->guard()->evaluate($this->context(
            species: $this->testudoHermanni(),
            hatchDate: $this->weeksAgo(20),
            weightG: 60,
            confirmations: [
                'meldung_bestaetigt' => true,
                'meldung_datum' => '2026-06-15',
                'kennzeichnung_art' => 'fotodokumentation',
            ],
        ));

        self::assertTrue($decision->blocked);
        self::assertContains(
            'eu_bescheinigung_fehlt',
            array_map(static fn($reason): string => $reason->code, $decision->blockingReasons()),
        );
    }

    /**
     * Akzeptanzkriterium: mit Nummer landet sie in der Prüf-Queue.
     */
    public function testAnhangAMitBescheinigungsnummerLandetInDerPruefQueue(): void
    {
        $decision = $this->guard()->evaluate($this->vollstaendigeSchildkroete());

        $this->assertNotBlocked($decision);
        self::assertTrue($decision->requiresReview);
        $this->assertNoMissingTexts($decision);
    }

    public function testUnkritischeAnzeigeLaeuftOhneAuflagenDurch(): void
    {
        $decision = $this->guard()->evaluate($this->context(
            species: $this->bartagame(),
            hatchDate: $this->weeksAgo(12),
            weightG: 40,
        ));

        $this->assertNotBlocked($decision);
        self::assertFalse($decision->requiresReview);
        self::assertSame(['hatch_date', 'weight_g'], $decision->requiredFields);
    }

    /**
     * Regel 1 und Regel 2 duerfen sich nicht ins Gehege kommen: Bei Anhang A
     * zaehlt die Bescheinigung, nicht zusaetzlich der Herkunftsnachweis.
     */
    public function testAnhangASchliesstDenHerkunftsnachweisAus(): void
    {
        $decision = $this->guard()->evaluate($this->vollstaendigeSchildkroete());

        self::assertNotContains('legal_docs.herkunftsnachweis', $decision->requiredFields);
        self::assertNotContains('anhang_b', $decision->triggeredRules());
    }

    public function testAnhangBArtVerlangtHerkunftsnachweis(): void
    {
        $decision = $this->guard()->evaluate($this->context(
            species: $this->species(
                scientificName: 'Python regius',
                euAnnex: EuAnnex::B,
                bnatschgStatus: BnatschgStatus::Besonders,
                meldepflicht: true,
                dokuPflicht: true,
                minAbgabeAlterWochen: 8,
                minAbgabeGewichtG: 60,
            ),
            hatchDate: $this->weeksAgo(12),
            weightG: 120,
            confirmations: ['meldung_bestaetigt' => true, 'meldung_datum' => '2026-07-01'],
        ));

        self::assertTrue($decision->blocked);
        self::assertContains('legal_docs.herkunftsnachweis', $decision->requiredFields);
        self::assertNotContains('anhang_a', $decision->triggeredRules());
    }

    public function testMehrereRegelnSammelnIhreGruendeGemeinsam(): void
    {
        $decision = $this->guard()->evaluate($this->context(
            species: $this->testudoHermanni(),
            country: Country::De,
            handover: Handover::Tiertransport,
            seller: new SellerProfile(isCommercial: true),
            hatchDate: $this->weeksAgo(2),
            weightG: 5,
        ));

        $triggered = $decision->triggeredRules();

        foreach (['anhang_a', 'meldepflicht', 'kennzeichnung', 'tierschutz', 'versand', 'gewerblichkeit'] as $rule) {
            self::assertContains($rule, $triggered);
        }

        $codes = array_map(static fn($reason): string => $reason->code, $decision->blockingReasons());

        self::assertContains('eu_bescheinigung_fehlt', $codes);
        self::assertContains('meldung_nicht_bestaetigt', $codes);
        self::assertContains('kennzeichnung_fehlt', $codes);
        self::assertContains('abgabealter_unterschritten', $codes);
        self::assertContains('abgabegewicht_unterschritten', $codes);
        self::assertContains('erlaubnis_11_fehlt', $codes);
    }

    /**
     * Phase 4 baut den Schritt "Rechtsnachweise" aus dieser Liste.
     */
    public function testRequiredFieldsLiefertAlleFelderOhneDubletten(): void
    {
        $fields = $this->guard()->requiredFields($this->context(
            species: $this->testudoHermanni(),
            confirmations: ['kennzeichnung_art' => 'transponder'],
        ));

        self::assertSame($fields, array_values(array_unique($fields)));

        foreach ([
            'legal_docs.eu_bescheinigung',
            'legal_docs.eu_bescheinigung.reference_number',
            'meldung_bestaetigt',
            'meldung_datum',
            'kennzeichnung_art',
            'kennzeichnung_nummer',
            'hatch_date',
            'weight_g',
        ] as $field) {
            self::assertContains($field, $fields);
        }
    }

    public function testGesuchLoestKeineNachweisregelnAus(): void
    {
        $decision = $this->guard()->evaluate($this->context(
            species: $this->testudoHermanni(),
            type: ListingType::Gesuch,
        ));

        $this->assertNotBlocked($decision);
        self::assertSame([], $decision->requiredFields);
    }

    public function testJederHinweisStammtAusDerTexttabelle(): void
    {
        $decision = $this->guard()->evaluate($this->context(
            species: $this->testudoHermanni(),
            handover: Handover::Tiertransport,
            seller: new SellerProfile(isCommercial: true),
        ));

        self::assertNotSame([], $decision->notices);

        foreach ($decision->notices as $notice) {
            self::assertFalse($notice->textMissing, \sprintf('Rechtstext "%s" fehlt.', $notice->key));
            self::assertNotSame('', trim($notice->body));
        }
    }

    public function testAuditNutzlastIstSerialisierbar(): void
    {
        $payload = $this->guard()->evaluate($this->vollstaendigeSchildkroete())->auditPayload();

        $json = json_encode($payload, \JSON_THROW_ON_ERROR);

        self::assertJson($json);
        self::assertFalse($payload['blocked']);
        self::assertTrue($payload['requires_review']);
    }

    public function testEntscheidungOhneRegeltrefferIstLeer(): void
    {
        $decision = LegalDecision::allowed();

        self::assertFalse($decision->blocked);
        self::assertFalse($decision->requiresReview);
        self::assertSame([], $decision->triggeredRules());
    }
}
