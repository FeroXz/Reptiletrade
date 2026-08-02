<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Legal;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Listing\CbStatus;
use Reptilienmarkt\Domain\Listing\Handover;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Species\BnatschgStatus;
use Reptilienmarkt\Domain\Species\CareLevel;
use Reptilienmarkt\Domain\Species\EuAnnex;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Legal\LegalContext;
use Reptilienmarkt\Legal\LegalDecision;
use Reptilienmarkt\Legal\LegalDecisionDraft;
use Reptilienmarkt\Legal\LegalDocumentInput;
use Reptilienmarkt\Legal\LegalReason;
use Reptilienmarkt\Legal\LegalRule;
use Reptilienmarkt\Legal\LegalTextResolver;
use Reptilienmarkt\Legal\SellerProfile;
use Reptilienmarkt\Tests\Support\FrozenClock;

abstract class LegalTestCase extends TestCase
{
    protected const string NOW = '2026-08-02T12:00:00+00:00';

    protected FrozenClock $clock;

    protected InMemoryLegalTextRepository $texts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable(self::NOW));
        $this->texts = new InMemoryLegalTextRepository();
        $this->texts->loadBundled(\dirname(__DIR__, 2) . '/data/legal_texts.json');
    }

    protected function draft(): LegalDecisionDraft
    {
        return new LegalDecisionDraft(new LegalTextResolver($this->texts));
    }

    /**
     * Wertet genau eine Regel aus — das ist die Einheit, die hier getestet wird.
     */
    protected function evaluateRule(LegalRule $rule, LegalContext $context): LegalDecision
    {
        $draft = $this->draft();

        if ($rule->applies($context)) {
            $rule->evaluate($context, $draft);
        }

        return $draft->build();
    }

    protected function species(
        string $scientificName = 'Pogona vitticeps',
        ?EuAnnex $euAnnex = null,
        BnatschgStatus $bnatschgStatus = BnatschgStatus::NichtGeschuetzt,
        bool $meldepflicht = false,
        bool $dokuPflicht = false,
        bool $gefahrtier = false,
        ?int $minAbgabeAlterWochen = null,
        ?int $minAbgabeGewichtG = null,
    ): Species {
        return new Species(
            1,
            $scientificName,
            'Testart',
            strtolower(str_replace(' ', '-', $scientificName)),
            null,
            null,
            null,
            $euAnnex,
            $bnatschgStatus,
            $meldepflicht,
            $dokuPflicht,
            $gefahrtier,
            CareLevel::Einsteiger,
            null,
            null,
            $minAbgabeAlterWochen,
            $minAbgabeGewichtG,
        );
    }

    /**
     * @param array<string, LegalDocumentInput> $documents
     * @param array<string, scalar|null>        $confirmations
     */
    protected function context(
        ?Species $species = null,
        ListingType $type = ListingType::Verkauf,
        Country $country = Country::De,
        Handover $handover = Handover::Abholung,
        ?SellerProfile $seller = null,
        ?string $admin1 = null,
        ?DateTimeImmutable $hatchDate = null,
        ?int $weightG = null,
        CbStatus $cbStatus = CbStatus::Nachzucht,
        array $documents = [],
        array $confirmations = [],
    ): LegalContext {
        return new LegalContext(
            $species ?? $this->species(),
            $type,
            $country,
            $handover,
            $seller ?? new SellerProfile(),
            $admin1,
            $hatchDate,
            $weightG,
            $cbStatus,
            $documents,
            $confirmations,
        );
    }

    protected function weeksAgo(int $weeks): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::NOW))->modify(\sprintf('-%d weeks', $weeks));
    }

    protected function daysAgo(int $days): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::NOW))->modify(\sprintf('-%d days', $days));
    }

    /**
     * @return list<string>
     */
    protected function codes(LegalDecision $decision): array
    {
        return array_map(static fn(LegalReason $reason): string => $reason->code, $decision->reasons);
    }

    protected function assertBlockedBecause(LegalDecision $decision, string $code): void
    {
        self::assertTrue($decision->blocked, 'Erwartet: blockiert. Gruende: ' . implode(', ', $this->codes($decision)));

        $blockingCodes = array_map(
            static fn(LegalReason $reason): string => $reason->code,
            $decision->blockingReasons(),
        );

        self::assertContains($code, $blockingCodes);
    }

    protected function assertNotBlocked(LegalDecision $decision): void
    {
        self::assertFalse(
            $decision->blocked,
            'Erwartet: nicht blockiert. Gruende: ' . implode(', ', $this->codes($decision)),
        );
    }

    /**
     * Alle Hinweise muessen aus der Texttabelle stammen — ein Platzhalter
     * bedeutet, dass ein Schluessel in data/legal_texts.json fehlt.
     */
    protected function assertNoMissingTexts(LegalDecision $decision): void
    {
        self::assertFalse($decision->hasMissingLegalText(), 'Mindestens ein Rechtstext fehlt.');
    }
}
