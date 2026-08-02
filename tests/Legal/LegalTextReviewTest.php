<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Legal;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Legal\Disclaimer;
use Reptilienmarkt\Legal\LegalText;
use Reptilienmarkt\Legal\LegalTextReview;
use Reptilienmarkt\Tests\Support\FrozenClock;

/**
 * Die Admin-Warnung fuer ueberfaellige Rechtstexte.
 */
#[CoversClass(LegalTextReview::class)]
#[CoversClass(LegalText::class)]
final class LegalTextReviewTest extends TestCase
{
    private const string NOW = '2026-08-02T12:00:00+00:00';

    private FrozenClock $clock;

    private InMemoryLegalTextRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable(self::NOW));
        $this->repository = new InMemoryLegalTextRepository();
    }

    private function text(string $key, ?string $reviewedAt): LegalText
    {
        return new LegalText(
            null,
            $key,
            'Titel',
            'Text',
            'DE',
            null,
            $reviewedAt === null ? null : new DateTimeImmutable($reviewedAt),
        );
    }

    private function review(int $months = 12): LegalTextReview
    {
        return new LegalTextReview($this->repository, $this->clock, $months);
    }

    public function testNieGeprueftGiltAlsUeberfaellig(): void
    {
        $this->repository->save($this->text('legal.anhang_a', null));

        $stale = $this->review()->stale();

        self::assertCount(1, $stale);
        self::assertSame('legal.anhang_a', $stale[0]->key);
        self::assertCount(1, $this->review()->neverReviewed());
    }

    public function testFrischGeprueftIstNichtUeberfaellig(): void
    {
        $this->repository->save($this->text('legal.anhang_b', '2026-07-01T00:00:00+00:00'));

        self::assertSame([], $this->review()->stale());
        self::assertFalse($this->review()->hasStale());
        self::assertNull($this->review()->warning());
    }

    /**
     * Grenzfall: genau ein Tag vor Ablauf der Frist.
     */
    public function testEinenTagVorFristablaufNochNichtUeberfaellig(): void
    {
        $this->repository->save($this->text('legal.meldepflicht', '2025-08-03T00:00:00+00:00'));

        self::assertSame([], $this->review()->stale());
    }

    /**
     * Grenzfall: einen Tag ueber der Frist.
     */
    public function testEinenTagNachFristablaufUeberfaellig(): void
    {
        $this->repository->save($this->text('legal.meldepflicht', '2025-08-01T00:00:00+00:00'));

        self::assertCount(1, $this->review()->stale());
    }

    public function testFristIstKonfigurierbar(): void
    {
        $this->repository->save($this->text('legal.versand', '2026-01-01T00:00:00+00:00'));

        self::assertSame([], $this->review(12)->stale());
        self::assertCount(1, $this->review(6)->stale());
        self::assertSame(6, $this->review(6)->maxAgeMonths());
    }

    public function testWarnungNenntDieBetroffenenSchluessel(): void
    {
        $this->repository->save($this->text('legal.anhang_a', null));
        $this->repository->save($this->text('legal.gefahrtier', '2020-01-01T00:00:00+00:00'));
        $this->repository->save($this->text('legal.versand', '2026-07-01T00:00:00+00:00'));

        $warning = $this->review()->warning();

        self::assertNotNull($warning);
        self::assertStringContainsString('2 Rechtstext', $warning);
        self::assertStringContainsString('legal.anhang_a', $warning);
        self::assertStringContainsString('legal.gefahrtier', $warning);
        self::assertStringNotContainsString('legal.versand', $warning);
    }

    public function testFreigabeSetztDieFristZurueck(): void
    {
        $this->repository->save($this->text('legal.anhang_a', null));
        self::assertTrue($this->review()->hasStale());

        $this->repository->markReviewed('legal.anhang_a', 'DE', 1);

        self::assertFalse($this->review()->hasStale());
    }

    public function testDisclaimerNenntDieVerantwortung(): void
    {
        self::assertSame('Keine Rechtsberatung', Disclaimer::TITLE);
        self::assertStringContainsString('Betreiber', Disclaimer::BODY);
        self::assertStringContainsString('ersetzt', Disclaimer::BODY);
        self::assertArrayHasKey('body', Disclaimer::toArray());
    }
}
