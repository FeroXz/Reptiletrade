<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Genetics;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Genetics\Punnett;

#[CoversClass(Punnett::class)]
final class PunnettTest extends TestCase
{
    public function testKlassischesFeldZweierTraeger(): void
    {
        $punnett = Punnett::fromGametes('hypo', 'Hypo', ['hypo', '+'], ['hypo', '+']);

        self::assertSame(
            [
                ['hypo/hypo', 'hypo/+'],
                ['hypo/+', '+/+'],
            ],
            $punnett->grid(),
        );

        $distribution = $punnett->distribution();
        self::assertEqualsWithDelta(0.25, $distribution['hypo/hypo'], 0.0001);
        self::assertEqualsWithDelta(0.50, $distribution['hypo/+'], 0.0001);
        self::assertEqualsWithDelta(0.25, $distribution['+/+'], 0.0001);
        self::assertEqualsWithDelta(1.0, array_sum($distribution), 0.0001);
    }

    public function testGenotypenSindNachHaeufigkeitSortiert(): void
    {
        $punnett = Punnett::fromGametes('hypo', 'Hypo', ['hypo', '+'], ['hypo', '+']);

        self::assertSame('hypo/+', $punnett->genotypes()[0]);
    }

    public function testHomozygotEltenteilLiefertNurEineZeile(): void
    {
        $punnett = Punnett::fromGametes('hypo', 'Hypo', ['hypo', 'hypo'], ['+', '+']);

        self::assertSame(['hypo/+' => 1.0], $punnett->distribution());
    }

    /**
     * Nicht lebensfaehige Felder bleiben stehen und werden gekennzeichnet — die
     * Verteilung der Ueberlebenden steht daneben.
     */
    public function testLetaleFelderWerdenAusgewiesenUndNichtEntfernt(): void
    {
        $punnett = Punnett::fromGametes('spider', 'Spider', ['spider', '+'], ['spider', '+'])
            ->withLethal(['spider/spider']);

        self::assertTrue($punnett->isLethal('spider/spider'));
        self::assertEqualsWithDelta(0.25, $punnett->lethalShare(), 0.0001);
        self::assertArrayHasKey('spider/spider', $punnett->distribution());

        $viable = $punnett->viableDistribution();
        self::assertArrayNotHasKey('spider/spider', $viable);
        self::assertEqualsWithDelta(2 / 3, $viable['spider/+'], 0.0001);
        self::assertEqualsWithDelta(1 / 3, $viable['+/+'], 0.0001);
        self::assertEqualsWithDelta(1.0, array_sum($viable), 0.0001);
    }

    public function testAlleleLassenSichZurueckLesen(): void
    {
        $punnett = Punnett::fromGametes('zero', 'Zero', ['zero', '+'], ['zero', '+']);

        self::assertSame(['zero', '+'], $punnett->allelesOf('zero/+'));
        self::assertSame([], $punnett->allelesOf('gibt/es/nicht'));
    }

    public function testHemizygoteNachkommenTragenNurEinAllel(): void
    {
        $punnett = new Punnett('zero', 'Zero — Töchter', ['zero', '+'], ['W'], [[['zero']], [['+']]]);

        self::assertSame([['zero'], ['+']], $punnett->grid());
        self::assertEqualsWithDelta(0.5, $punnett->distribution()['zero'], 0.0001);
    }

    public function testFeldOhneZellenIstUngueltig(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Punnett('zero', 'Zero', [], [], []);
    }

    public function testSerialisierungBleibtErhalten(): void
    {
        $punnett = Punnett::fromGametes('spider', 'Spider', ['spider', '+'], ['spider', '+'])
            ->withLethal(['spider/spider']);

        $restored = Punnett::fromArray($punnett->toArray());

        self::assertSame($punnett->grid(), $restored->grid());
        self::assertSame($punnett->label, $restored->label);
        self::assertSame($punnett->distribution(), $restored->distribution());
        self::assertTrue($restored->isLethal('spider/spider'));
    }

    public function testBeschriftungLaesstSichErsetzen(): void
    {
        $punnett = Punnett::fromGametes('zero', 'Zero', ['zero'], ['+'])->withLabel('Zero — Söhne');

        self::assertSame('Zero — Söhne', $punnett->label);
    }
}
