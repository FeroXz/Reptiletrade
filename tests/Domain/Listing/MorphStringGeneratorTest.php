<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Listing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Listing\MorphSelection;
use Reptilienmarkt\Domain\Listing\MorphStringGenerator;
use Reptilienmarkt\Domain\Listing\Zygosity;
use Reptilienmarkt\Domain\Species\Inheritance;
use Reptilienmarkt\Domain\Species\Morph;

#[CoversClass(MorphStringGenerator::class)]
#[CoversClass(MorphSelection::class)]
final class MorphStringGeneratorTest extends TestCase
{
    private MorphStringGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new MorphStringGenerator();
    }

    /**
     * @param list<string> $aliases
     */
    private function morph(
        string $name,
        Inheritance $inheritance = Inheritance::Recessive,
        array $aliases = [],
        ?string $alleleGroup = null,
        bool $lethal = false,
        int $id = 1,
    ): Morph {
        return new Morph($id, 1, $name, $inheritance, $aliases, $alleleGroup, $lethal);
    }

    /**
     * Das Beispiel aus der Aufgabenstellung.
     */
    public function testBeispielAusDerAufgabenstellung(): void
    {
        $selection = [
            new MorphSelection($this->morph('Hypomelanistic', aliases: ['Hypo'], id: 1)),
            new MorphSelection($this->morph('Translucent', aliases: ['Trans'], id: 2)),
            new MorphSelection($this->morph('Zero', id: 3), Zygosity::Het),
        ];

        self::assertSame('Hypo Trans het Zero', $this->generator->morphString($selection));
    }

    public function testSichtbareMerkmaleStehenVorDenHetAngaben(): void
    {
        $selection = [
            new MorphSelection($this->morph('Zero', id: 1), Zygosity::Het),
            new MorphSelection($this->morph('Albino', id: 2)),
        ];

        self::assertSame('Albino het Zero', $this->generator->morphString($selection));
    }

    public function testMoeglicheHetStehenGanzHinten(): void
    {
        $selection = [
            new MorphSelection($this->morph('Clown', id: 1), Zygosity::PossHet50),
            new MorphSelection($this->morph('Zero', id: 2), Zygosity::Het),
            new MorphSelection($this->morph('Albino', id: 3)),
            new MorphSelection($this->morph('Piebald', id: 4), Zygosity::PossHet66),
        ];

        self::assertSame(
            'Albino het Zero 66% poss. het Piebald 50% poss. het Clown',
            $this->generator->morphString($selection),
        );
    }

    /**
     * Dieselbe Auswahl muss immer denselben String ergeben — sonst haengt der
     * Anzeigentitel von der Eingabereihenfolge ab.
     */
    public function testReihenfolgeDerEingabeIstEgal(): void
    {
        $a = [
            new MorphSelection($this->morph('Translucent', aliases: ['Trans'], id: 1)),
            new MorphSelection($this->morph('Hypomelanistic', aliases: ['Hypo'], id: 2)),
        ];
        $b = array_reverse($a);

        self::assertSame($this->generator->morphString($a), $this->generator->morphString($b));
    }

    public function testKuerzesterAliasGewinnt(): void
    {
        $selection = [new MorphSelection($this->morph('Piebald', aliases: ['Pied'], id: 1))];

        self::assertSame('Pied', $this->generator->morphString($selection));
    }

    public function testOhneMerkmaleBleibtDerStringLeer(): void
    {
        self::assertSame('', $this->generator->morphString([]));
        self::assertSame('', $this->generator->genotype([]));
        self::assertSame([], $this->generator->warnings([]));
    }

    public function testGenotypRezessiv(): void
    {
        $selection = [
            new MorphSelection($this->morph('Hypomelanistic', aliases: ['Hypo'], id: 1)),
            new MorphSelection($this->morph('Zero', id: 2), Zygosity::Het),
            new MorphSelection($this->morph('Albino', id: 3), Zygosity::PossHet66),
        ];

        self::assertSame('albino/? hypo/hypo zero/+', $this->generator->genotype($selection));
    }

    public function testGenotypDominantUndUnvollstaendigDominant(): void
    {
        $selection = [
            new MorphSelection($this->morph('Spider', Inheritance::Dominant, id: 1)),
            new MorphSelection($this->morph('Pastel', Inheritance::IncompleteDominant, id: 2)),
        ];

        self::assertSame('pastel/+ spider/+', $this->generator->genotype($selection));
    }

    /**
     * Polygene und liniengezuechtete Merkmale haben keinen einzelnen Genort.
     */
    public function testMerkmaleOhneGenortStehenNichtImGenotyp(): void
    {
        $selection = [
            new MorphSelection($this->morph('Tangerine', Inheritance::Polygenic, id: 1)),
            new MorphSelection($this->morph('Jungle', Inheritance::LineBred, id: 2)),
            new MorphSelection($this->morph('Paradox', Inheritance::Paradox, id: 3)),
            new MorphSelection($this->morph('Albino', id: 4)),
        ];

        self::assertSame('albino/albino', $this->generator->genotype($selection));
        // Im Anzeige-String tauchen sie sehr wohl auf.
        self::assertSame('Albino Jungle Paradox Tangerine', $this->generator->morphString($selection));
    }

    public function testWarnungBeiLetalkombination(): void
    {
        $selection = [new MorphSelection($this->morph('Spider', Inheritance::Dominant, lethal: true, id: 1))];

        $warnungen = $this->generator->warnings($selection);

        self::assertCount(1, $warnungen);
        self::assertStringContainsString('nicht lebensfähig', $warnungen[0]);
    }

    public function testWarnungBeiHetAufNichtRezessivemMerkmal(): void
    {
        $selection = [new MorphSelection($this->morph('Pastel', Inheritance::IncompleteDominant, id: 1), Zygosity::Het)];

        $warnungen = $this->generator->warnings($selection);

        self::assertCount(1, $warnungen);
        self::assertStringContainsString('het-Angabe', $warnungen[0]);
    }

    public function testWarnungBeiAllelischenMerkmalen(): void
    {
        $selection = [
            new MorphSelection($this->morph('Zero', alleleGroup: 'zero_witblits', id: 1)),
            new MorphSelection($this->morph('Witblits', alleleGroup: 'zero_witblits', id: 2)),
        ];

        $warnungen = $this->generator->warnings($selection);

        self::assertCount(1, $warnungen);
        self::assertStringContainsString('denselben Genort', $warnungen[0]);
    }

    public function testKeineWarnungOhneAllelgruppe(): void
    {
        $selection = [
            new MorphSelection($this->morph('Hypo', id: 1)),
            new MorphSelection($this->morph('Zero', id: 2)),
        ];

        self::assertSame([], $this->generator->warnings($selection));
    }
}
