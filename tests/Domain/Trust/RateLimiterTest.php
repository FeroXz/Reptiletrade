<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Trust;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Trust\RateLimit;
use Reptilienmarkt\Domain\Trust\RateLimitConfigurationException;
use Reptilienmarkt\Domain\Trust\RateLimiter;
use Reptilienmarkt\Infra\Persistence\PdoRateLimitRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(RateLimiter::class)]
#[CoversClass(PdoRateLimitRepository::class)]
final class RateLimiterTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private RateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-03T12:00:00+00:00'));
        $this->limiter = new RateLimiter(
            new PdoRateLimitRepository($this->database),
            $this->clock,
            ['test' => new RateLimit('test', 3, 600)],
        );
    }

    public function testInnerhalbDerGrenzeGehtDurch(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $entscheidung = $this->limiter->attempt('test', 'konto-1');

            self::assertTrue($entscheidung->allowed, 'Versuch ' . ($i + 1));
            self::assertSame(2 - $i, $entscheidung->remaining);
        }
    }

    public function testDerVierteVersuchWirdAbgewiesen(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->limiter->attempt('test', 'konto-1');
        }

        self::assertFalse($this->limiter->attempt('test', 'konto-1')->allowed);
    }

    /**
     * Ein abgewiesener Versuch darf die Sperre nicht verlaengern — sonst
     * sperrt sich aus, wer entnervt weiterklickt.
     */
    public function testAbgewieseneVersucheZaehlenNichtMit(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->limiter->attempt('test', 'konto-1');
        }

        $ersteAbweisung = $this->limiter->attempt('test', 'konto-1');

        for ($i = 0; $i < 5; ++$i) {
            $this->limiter->attempt('test', 'konto-1');
        }

        $spaetereAbweisung = $this->limiter->attempt('test', 'konto-1');

        self::assertEquals($ersteAbweisung->retryAt, $spaetereAbweisung->retryAt);
    }

    public function testDasFensterGleitetMit(): void
    {
        $this->limiter->attempt('test', 'konto-1');
        $this->limiter->attempt('test', 'konto-1');
        $this->limiter->attempt('test', 'konto-1');

        self::assertFalse($this->limiter->attempt('test', 'konto-1')->allowed);

        // Fuenf Minuten spaeter liegen alle drei Treffer noch im Fenster.
        $this->clock->travelTo(new DateTimeImmutable('2026-08-03T12:05:00+00:00'));
        self::assertFalse($this->limiter->attempt('test', 'konto-1')->allowed);

        // Nach zehn Minuten und einer Sekunde ist der erste herausgefallen.
        $this->clock->travelTo(new DateTimeImmutable('2026-08-03T12:10:01+00:00'));
        self::assertTrue($this->limiter->attempt('test', 'konto-1')->allowed);
    }

    public function testKennungenWerdenGetrenntGezaehlt(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->limiter->attempt('test', 'konto-1');
        }

        self::assertTrue($this->limiter->attempt('test', 'konto-2')->allowed);
    }

    public function testUnbekanntesLimitIstEinFehlerUndKeinFreibrief(): void
    {
        $this->expectException(RateLimitConfigurationException::class);

        $this->limiter->attempt('gibt-es-nicht', 'konto-1');
    }

    public function testNachsehenZaehltNichtMit(): void
    {
        self::assertSame(3, $this->limiter->remaining('test', 'konto-1'));
        self::assertSame(3, $this->limiter->remaining('test', 'konto-1'));

        $this->limiter->attempt('test', 'konto-1');

        self::assertSame(2, $this->limiter->remaining('test', 'konto-1'));
    }

    public function testAufraeumenLoeschtAlteTreffer(): void
    {
        $repository = new PdoRateLimitRepository($this->database);
        $this->limiter->attempt('test', 'konto-1');

        self::assertSame(1, $repository->purgeBefore(new DateTimeImmutable('2026-08-03T13:00:00+00:00')));
        self::assertSame(3, $this->limiter->remaining('test', 'konto-1'));
    }
}
