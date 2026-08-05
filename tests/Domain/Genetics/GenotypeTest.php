<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Genetics;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Genetics\Genotype;

#[CoversClass(Genotype::class)]
final class GenotypeTest extends TestCase
{
    public function testHomozygot(): void
    {
        $genotype = new Genotype(['red' => ['red', 'red']]);

        self::assertTrue($genotype->isHomozygous('red'));
        self::assertFalse($genotype->isHeterozygous('red'));
        self::assertFalse($genotype->isHemizygous('red'));
    }

    public function testHeterozygot(): void
    {
        $genotype = new Genotype(['red' => ['red', '+']]);

        self::assertTrue($genotype->isHeterozygous('red'));
        self::assertFalse($genotype->isHomozygous('red'));
    }

    /**
     * Ein Allel: das heterogametische Geschlecht bei geschlechtsgebundenen
     * Merkmalen.
     */
    public function testHemizygot(): void
    {
        $genotype = new Genotype(['zero' => ['zero']]);

        self::assertCount(1, $genotype->alleles()['zero']);
        self::assertTrue($genotype->isHemizygous('zero'));
        self::assertFalse($genotype->isHomozygous('zero'));
        self::assertFalse($genotype->isHeterozygous('zero'));
    }

    public function testLeereAllelListeIstUngueltig(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Genotype(['red' => []]);
    }

    public function testMehrAlsZweiAlleleSindUngueltig(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Genotype(['red' => ['red', 'red', 'red']]);
    }

    public function testLeererAllelnameIstUngueltig(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Genotype(['red' => ['red', ' ']]);
    }

    /**
     * Der Wildtyp steht hinten, damit dieselbe Anlage immer dieselbe Kurzform
     * ergibt — sonst haengt der Verteilungsschluessel an der Eingabereihenfolge.
     */
    public function testAlleleWerdenKanonischSortiert(): void
    {
        $genotype = new Genotype(['zero' => ['+', 'zero']]);

        self::assertSame(['zero', '+'], $genotype->alleles()['zero']);
        self::assertSame('zero/+', $genotype->notation('zero'));
    }

    public function testGenorteWerdenSortiert(): void
    {
        $genotype = new Genotype(['zero' => ['zero', 'zero'], 'albino' => ['albino', '+']]);

        self::assertSame(['albino', 'zero'], $genotype->loci());
    }

    public function testNichtBelegterGenortLiefertWildtyp(): void
    {
        $genotype = new Genotype([]);

        self::assertSame(['+', '+'], $genotype->at('zero'));
        self::assertSame(['+'], $genotype->at('zero', hemizygous: true));
        self::assertTrue($genotype->isWildtypeAt('zero'));
        self::assertFalse($genotype->has('zero'));
    }

    public function testTraegerschaftUndDosis(): void
    {
        $genotype = new Genotype(['zero' => ['zero', 'zero'], 'hypo' => ['hypo', '+']]);

        self::assertTrue($genotype->carries('zero', 'zero'));
        self::assertFalse($genotype->carries('hypo', 'zero'));
        self::assertSame(2, $genotype->dosage('zero', 'zero'));
        self::assertSame(1, $genotype->dosage('hypo', 'hypo'));
        self::assertSame(0, $genotype->dosage('hypo', 'zero'));
    }

    public function testGleichheitIstUnabhaengigVonDerReihenfolge(): void
    {
        $first = new Genotype(['zero' => ['+', 'zero'], 'hypo' => ['hypo', 'hypo']]);
        $second = new Genotype(['hypo' => ['hypo', 'hypo'], 'zero' => ['zero', '+']]);

        self::assertTrue($first->equals($second));
        self::assertFalse($first->equals(new Genotype(['zero' => ['zero', 'zero']])));
    }

    public function testSerialisierungBleibtErhalten(): void
    {
        $genotype = new Genotype(['zero' => ['zero', '+'], 'hypo' => ['hypo', 'hypo']]);

        self::assertTrue($genotype->equals(Genotype::fromArray($genotype->toArray())));
    }

    public function testFehlerhafteSerialisierungWirdAbgewiesen(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Genotype::fromArray(['zero' => 'zero']);
    }

    public function testWithSetztEinenGenort(): void
    {
        $genotype = (new Genotype(['zero' => ['zero', '+']]))->with('hypo', 'hypo', 'hypo');

        self::assertTrue($genotype->isHomozygous('hypo'));
        self::assertTrue($genotype->isHeterozygous('zero'));
    }
}
