<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Support\Timestamp;

/**
 * Regressionstest.
 *
 * Die Uhr der Anwendung laeuft in Europe/Berlin, gespeichert wird Text mit
 * angehaengtem Z. Wer eine Berliner Zeit direkt formatiert, behauptet damit
 * eine UTC-Zeit, die es nicht ist — beim Lesen liegt sie ein bis zwei Stunden
 * in der Zukunft. Sitzungen und Einmal-Token lebten dadurch laenger als
 * eingestellt, Kontosperren endeten frueher.
 */
#[CoversClass(Timestamp::class)]
final class TimestampTest extends TestCase
{
    public function testBerlinerSommerzeitWirdNachUtcUmgerechnet(): void
    {
        $berlin = new DateTimeImmutable('2026-08-03 14:00:00', new DateTimeZone('Europe/Berlin'));

        self::assertSame('2026-08-03T12:00:00Z', Timestamp::utc($berlin));
    }

    public function testBerlinerWinterzeitWirdNachUtcUmgerechnet(): void
    {
        $berlin = new DateTimeImmutable('2026-01-15 14:00:00', new DateTimeZone('Europe/Berlin'));

        self::assertSame('2026-01-15T13:00:00Z', Timestamp::utc($berlin));
    }

    public function testUtcBleibtUnveraendert(): void
    {
        $utc = new DateTimeImmutable('2026-08-03 12:00:00', new DateTimeZone('UTC'));

        self::assertSame('2026-08-03T12:00:00Z', Timestamp::utc($utc));
    }

    /**
     * Der eigentliche Punkt: Schreiben und Lesen muessen denselben Zeitpunkt
     * ergeben, sonst verschiebt sich jede Ablauffrist.
     */
    public function testSchreibenUndLesenErgibtDenselbenZeitpunkt(): void
    {
        $berlin = new DateTimeImmutable('2026-08-03 14:00:00', new DateTimeZone('Europe/Berlin'));
        $gelesen = Timestamp::parse(Timestamp::utc($berlin));

        self::assertNotNull($gelesen);
        self::assertSame($berlin->getTimestamp(), $gelesen->getTimestamp());
    }

    public function testEineStundeAblaufBleibtEineStunde(): void
    {
        $jetzt = new DateTimeImmutable('2026-08-03 14:00:00', new DateTimeZone('Europe/Berlin'));
        $ablauf = Timestamp::parse(Timestamp::utc($jetzt->modify('+60 minutes')));

        self::assertNotNull($ablauf);
        self::assertSame(3600, $ablauf->getTimestamp() - $jetzt->getTimestamp());
    }

    public function testNullBleibtNull(): void
    {
        self::assertNull(Timestamp::utcOrNull(null));
        self::assertNull(Timestamp::parse(null));
        self::assertNull(Timestamp::parse(''));
    }

    public function testNowIstUtc(): void
    {
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', Timestamp::now());
        self::assertSame(gmdate('Y-m-d\TH'), substr(Timestamp::now(), 0, 13));
    }
}
