<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Billing;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Billing\Money;

#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    public function testRechnenBleibtGanzzahlig(): void
    {
        $summe = (new Money(10))->plus(new Money(20));

        // In Fliesskomma waere 0.1 + 0.2 nicht 0.3 — bei Geld faellt genau das
        // irgendwann jemandem auf die Fuesse.
        self::assertSame(30, $summe->cents);
    }

    public function testWaehrungenLassenSichNichtVermischen(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Money(100, 'EUR'))->plus(new Money(100, 'CHF'));
    }

    public function testUngueltigerWaehrungscode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money(100, 'EURO');
    }

    /**
     * @return iterable<string, array{int, float, int}>
     */
    public static function steuerbetraege(): iterable
    {
        // Brutto, Satz, erwarteter enthaltener Steueranteil.
        yield '9,90 mit 19 %' => [990, 19.0, 158];
        yield '14,90 mit 19 %' => [1490, 19.0, 238];
        yield '99,00 mit 20 %' => [9900, 20.0, 1650];
        yield 'ohne Steuer' => [990, 0.0, 0];
    }

    #[DataProvider('steuerbetraege')]
    public function testEnthalteneSteuer(int $brutto, float $satz, int $erwartet): void
    {
        self::assertSame($erwartet, (new Money($brutto))->taxPortion($satz)->cents);
    }

    public function testNettoUndSteuerErgebenWiederBrutto(): void
    {
        $brutto = new Money(1490);
        $steuer = $brutto->taxPortion(19.0);

        self::assertSame($brutto->cents, $brutto->minus($steuer)->cents + $steuer->cents);
    }

    public function testFormatierung(): void
    {
        self::assertSame('9,90 EUR', (new Money(990))->format());
        self::assertSame('1.234,50 EUR', (new Money(123450))->format());
        self::assertSame('0,00 EUR', Money::zero()->format());
    }

    public function testNullbetrag(): void
    {
        self::assertTrue(Money::zero()->isZero());
        self::assertFalse((new Money(1))->isZero());
    }
}
