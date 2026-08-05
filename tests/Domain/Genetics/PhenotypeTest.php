<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Genetics;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Genetics\BreedingAnimal;
use Reptilienmarkt\Domain\Genetics\Genotype;
use Reptilienmarkt\Domain\Genetics\GenotypeFactory;
use Reptilienmarkt\Domain\Genetics\LocusMap;
use Reptilienmarkt\Domain\Genetics\Phenotype;
use Reptilienmarkt\Domain\Genetics\PhenotypeResolver;
use Reptilienmarkt\Domain\Genetics\RuleRegistry;
use Reptilienmarkt\Domain\Genetics\SexSystem;
use Reptilienmarkt\Domain\Listing\MorphSelection;
use Reptilienmarkt\Domain\Listing\MorphStringGenerator;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Listing\Zygosity;
use Reptilienmarkt\Domain\Species\Inheritance;
use Reptilienmarkt\Domain\Species\Morph;

#[CoversClass(Phenotype::class)]
#[CoversClass(PhenotypeResolver::class)]
#[CoversClass(GenotypeFactory::class)]
#[CoversClass(LocusMap::class)]
final class PhenotypeTest extends TestCase
{
    private Morph $hypo;

    private Morph $zero;

    private Morph $leatherback;

    private Morph $silkback;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hypo = new Morph(1, 1, 'Hypomelanistic', Inheritance::Recessive, ['Hypo']);
        $this->zero = new Morph(2, 1, 'Zero', Inheritance::Recessive, [], 'zero_witblits');
        $this->leatherback = new Morph(3, 1, 'Leatherback', Inheritance::IncompleteDominant, [], 'leatherback');
        $this->silkback = new Morph(4, 1, 'Silkback', Inheritance::IncompleteDominant, [], 'leatherback');
    }

    private function map(): LocusMap
    {
        return LocusMap::build(
            [$this->hypo, $this->zero, $this->leatherback, $this->silkback],
            ['Silkback' => 'Leatherback'],
        );
    }

    public function testSichtbareUndGetrageneMerkmale(): void
    {
        $phenotype = new Phenotype([
            new MorphSelection($this->hypo),
            new MorphSelection($this->zero, Zygosity::Het),
        ]);

        self::assertSame(['Hypomelanistic'], $phenotype->traits());
        self::assertSame(['Zero'], $phenotype->hets());
        self::assertFalse($phenotype->isWildtype());
    }

    /**
     * Ohne jedes Merkmal steht in einer Verteilung nicht die leere
     * Zeichenkette, sondern "Wildtyp" — eine leere Zeile waere nicht zu deuten.
     */
    public function testOhneMerkmaleStehtWildtyp(): void
    {
        self::assertSame(Phenotype::WILDTYPE_LABEL, Phenotype::wildtype()->morphString());
        self::assertTrue(Phenotype::wildtype()->isWildtype());
    }

    public function testGleichheitIstUnabhaengigVonDerReihenfolge(): void
    {
        $first = new Phenotype([new MorphSelection($this->hypo), new MorphSelection($this->zero, Zygosity::Het)]);
        $second = new Phenotype([new MorphSelection($this->zero, Zygosity::Het), new MorphSelection($this->hypo)]);

        self::assertTrue($first->equals($second));
        self::assertSame($first->signature(), $second->signature());
    }

    public function testFremdeEintraegeWerdenAbgewiesen(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore-next-line Absichtlich falscher Typ */
        new Phenotype(['Hypo']);
    }

    /**
     * Der Kern der Anbindung an den Anzeigenassistenten: Aus der
     * Merkmalsauswahl wird ein Genotyp, aus dem Genotyp wieder ein Phaenotyp —
     * und der ergibt denselben Morph-String, den der Assistent anzeigt. Zwei
     * Schreibweisen fuer dieselbe Sache waeren im Marktplatz ein Suchproblem.
     */
    public function testDerRueckwegErgibtDenselbenMorphStringWieDerAssistent(): void
    {
        $selection = [
            new MorphSelection($this->hypo),
            new MorphSelection($this->zero, Zygosity::Het),
        ];

        $map = $this->map();
        $genotype = (new GenotypeFactory($map))->mostLikely(new BreedingAnimal(1, Sex::Maennlich, $selection));
        $phenotype = (new PhenotypeResolver($map, RuleRegistry::default()))->resolve($genotype, Sex::Maennlich);

        self::assertSame(
            (new MorphStringGenerator())->morphString($selection),
            $phenotype->morphString(),
        );
        self::assertSame('Hypo het Zero', $phenotype->morphString());
    }

    public function testDieSuperformWirdAlsSolcheErkannt(): void
    {
        $map = $this->map();
        $resolver = new PhenotypeResolver($map, RuleRegistry::default());

        $basis = $resolver->resolve(new Genotype(['leatherback' => ['leatherback', '+']]));
        $super = $resolver->resolve(new Genotype(['leatherback' => ['leatherback', 'leatherback']]));

        self::assertSame('Leatherback', $basis->morphString());
        self::assertSame('Silkback', $super->morphString());
    }

    /**
     * Die Superform ist kein eigenes Allel: Wer sie am Elterntier auswaehlt,
     * meint ein reinerbiges Tier.
     */
    public function testDieAuswahlDerSuperformErgibtEinenReinerbigenGenotyp(): void
    {
        $genotype = (new GenotypeFactory($this->map()))->mostLikely(
            new BreedingAnimal(1, Sex::Weiblich, [new MorphSelection($this->silkback)]),
        );

        self::assertSame(['leatherback', 'leatherback'], $genotype->at('leatherback'));
    }

    public function testUnsichereAngabenErgebenMehrereMoeglichkeiten(): void
    {
        $possibilities = (new GenotypeFactory($this->map()))->possibilities(
            new BreedingAnimal(1, Sex::Maennlich, [new MorphSelection($this->hypo, Zygosity::PossHet66)]),
        );

        self::assertCount(2, $possibilities);
        self::assertEqualsWithDelta(2 / 3, $possibilities[0]['weight'], 0.0001);
        self::assertEqualsWithDelta(1 / 3, $possibilities[1]['weight'], 0.0001);
        self::assertEqualsWithDelta(1.0, array_sum(array_column($possibilities, 'weight')), 0.0001);
    }

    /**
     * Beim hemizygoten Geschlecht traegt der Genort nur ein Allel.
     */
    public function testGeschlechtsgebundenErgibtBeimHemizygotenGeschlechtEinAllel(): void
    {
        $ghost = new Morph(5, 1, 'Ghost', Inheritance::SexLinked);
        $map = LocusMap::build([$ghost]);
        $factory = new GenotypeFactory($map, SexSystem::Zw);

        $weibchen = $factory->mostLikely(new BreedingAnimal(1, Sex::Weiblich, [new MorphSelection($ghost)]));
        $maennchen = $factory->mostLikely(new BreedingAnimal(1, Sex::Maennlich, [new MorphSelection($ghost)]));

        self::assertSame(['ghost'], $weibchen->at('ghost'));
        self::assertSame(['ghost', 'ghost'], $maennchen->at('ghost'));
    }

    public function testMerkmaleEinerAnderenArtWerdenUebergangen(): void
    {
        $fremd = new Morph(99, 2, 'Anderes Merkmal', Inheritance::Recessive);

        $genotype = (new GenotypeFactory($this->map()))->mostLikely(
            new BreedingAnimal(1, Sex::Maennlich, [new MorphSelection($fremd)]),
        );

        self::assertSame([], $genotype->loci());
    }
}
