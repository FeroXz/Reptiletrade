<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Genetics;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Genetics\BreedingAnimal;
use Reptilienmarkt\Domain\Genetics\CrossSimulation;
use Reptilienmarkt\Domain\Genetics\GeneticsConfiguration;
use Reptilienmarkt\Domain\Genetics\GeneticWarning;
use Reptilienmarkt\Domain\Genetics\GenotypeFactory;
use Reptilienmarkt\Domain\Genetics\IncompatibleSpeciesException;
use Reptilienmarkt\Domain\Genetics\LethalCrossException;
use Reptilienmarkt\Domain\Genetics\LocusMap;
use Reptilienmarkt\Domain\Genetics\ParentSummary;
use Reptilienmarkt\Domain\Genetics\Phenotype;
use Reptilienmarkt\Domain\Genetics\PhenotypeResolver;
use Reptilienmarkt\Domain\Genetics\RuleRegistry;
use Reptilienmarkt\Domain\Genetics\SimulationResult;
use Reptilienmarkt\Domain\Listing\MorphSelection;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Listing\Zygosity;
use Reptilienmarkt\Domain\Species\Inheritance;
use Reptilienmarkt\Domain\Species\Morph;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Infra\Persistence\PdoMorphRepository;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

/**
 * Die Vererbungsrechnung an den Faellen, die ein Zuechter tatsaechlich
 * durchrechnet. Jeder Test ist ein Anwendungsfall — deshalb stehen die Zahlen
 * ausgeschrieben da und nicht als errechnete Erwartung.
 */
#[CoversClass(CrossSimulation::class)]
#[CoversClass(SimulationResult::class)]
#[CoversClass(GenotypeFactory::class)]
#[CoversClass(PhenotypeResolver::class)]
#[CoversClass(LocusMap::class)]
#[CoversClass(Phenotype::class)]
#[CoversClass(ParentSummary::class)]
final class CrossSimulationTest extends DatabaseTestCase
{
    private PdoMorphRepository $morphs;

    private PdoSpeciesRepository $speciesRepository;

    private FrozenClock $clock;

    private int $speciesId;

    /** @var array<string, Morph> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock(new DateTimeImmutable('2026-08-05T12:00:00+00:00'));
        $this->morphs = new PdoMorphRepository($this->database);
        $this->speciesRepository = new PdoSpeciesRepository($this->database);

        $this->speciesId = $this->speciesRepository->save(
            new Species(null, 'Pogona vitticeps', 'Bartagame', 'pogona-vitticeps'),
        );

        $this->addMorph('Hypomelanistic', Inheritance::Recessive, aliases: ['Hypo']);
        $this->addMorph('Zero', Inheritance::Recessive, alleleGroup: 'zero_witblits');
        $this->addMorph('Witblits', Inheritance::Recessive, alleleGroup: 'zero_witblits');
        $this->addMorph('Leatherback', Inheritance::IncompleteDominant, alleleGroup: 'leatherback');
        $this->addMorph('Silkback', Inheritance::IncompleteDominant, alleleGroup: 'leatherback');
        $this->addMorph('Dunner', Inheritance::Dominant);
        $this->addMorph('Wobble', Inheritance::Dominant, lethal: true);
        $this->addMorph('Ghost', Inheritance::SexLinked);
        $this->addMorph('Red', Inheritance::LineBred);
    }

    /**
     * @param list<string> $aliases
     */
    private function addMorph(
        string $name,
        Inheritance $inheritance,
        array $aliases = [],
        ?string $alleleGroup = null,
        bool $lethal = false,
    ): void {
        $id = $this->morphs->save(new Morph(null, $this->speciesId, $name, $inheritance, $aliases, $alleleGroup, $lethal));
        $this->catalog[$name] = $this->morphs->findById($id) ?? self::fail('Merkmal nicht gespeichert.');
    }

    private function morph(string $name, Zygosity $zygosity = Zygosity::Visual): MorphSelection
    {
        return new MorphSelection($this->catalog[$name] ?? self::fail('Unbekanntes Merkmal ' . $name), $zygosity);
    }

    /**
     * @param array<string, mixed> $overrides Ersetzt Eintraege des Art-Abschnitts
     */
    private function simulation(array $overrides = []): CrossSimulation
    {
        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 3) . '/config/genetik.php';

        /** @var array<string, array<string, mixed>> $species */
        $species = $config['arten'];
        $species['pogona-vitticeps'] = array_merge($species['pogona-vitticeps'], $overrides);
        $config['arten'] = $species;

        return new CrossSimulation(
            $this->morphs,
            $this->speciesRepository,
            new GeneticsConfiguration($config),
            $this->clock,
        );
    }

    /**
     * @param list<MorphSelection> $morphs
     */
    private function animal(Sex $sex, array $morphs = []): BreedingAnimal
    {
        return new BreedingAnimal($this->speciesId, $sex, $morphs);
    }

    // ------------------------------------------------------- Grundrechnungen

    public function testSichtbarRezessivMalWildtypErgibtAusschliesslichTraeger(): void
    {
        $result = $this->simulation()->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Hypomelanistic')]),
            $this->animal(Sex::Weiblich),
        );

        self::assertSame(['het Hypo' => 1.0], $result->offspringPhenotypes());
        self::assertSame(0.0, $result->lethalShare());
    }

    public function testZweiTraegerErgebenEinViertelSichtbare(): void
    {
        $result = $this->simulation()->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Hypomelanistic', Zygosity::Het)]),
            $this->animal(Sex::Weiblich, [$this->morph('Hypomelanistic', Zygosity::Het)]),
        );

        $phenotypes = $result->offspringPhenotypes();

        self::assertEqualsWithDelta(0.25, $phenotypes['Hypo'], 0.0001);
        self::assertEqualsWithDelta(0.50, $phenotypes['het Hypo'], 0.0001);
        self::assertEqualsWithDelta(0.25, $phenotypes[Phenotype::WILDTYPE_LABEL], 0.0001);
        self::assertEqualsWithDelta(1.0, array_sum($phenotypes), 0.0001);
    }

    public function testMehrereGenorteWerdenUnabhaengigKombiniert(): void
    {
        $result = $this->simulation()->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Dunner'), $this->morph('Hypomelanistic', Zygosity::Het)]),
            $this->animal(Sex::Weiblich, [$this->morph('Hypomelanistic', Zygosity::Het)]),
        );

        $phenotypes = $result->offspringPhenotypes();

        self::assertEqualsWithDelta(0.125, $phenotypes['Dunner Hypo'], 0.0001);
        self::assertEqualsWithDelta(0.25, $phenotypes['Dunner het Hypo'], 0.0001);
        self::assertEqualsWithDelta(0.125, $phenotypes[Phenotype::WILDTYPE_LABEL], 0.0001);
        self::assertEqualsWithDelta(1.0, array_sum($phenotypes), 0.0001);
    }

    /**
     * Zwei Merkmale desselben Genorts: Die Nachkommen zeigen beide zusammen und
     * sind keine Traeger.
     */
    public function testAllelischeMerkmaleErgebenEineMischform(): void
    {
        $result = $this->simulation()->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Zero')]),
            $this->animal(Sex::Weiblich, [$this->morph('Witblits')]),
        );

        self::assertSame(['Witblits Zero' => 1.0], $result->offspringPhenotypes());
        self::assertTrue($result->hasWarningOfType(GeneticWarning::TYPE_ALLELIC));
    }

    public function testSuperformEntstehtAusZweiBasisformen(): void
    {
        $result = $this->simulation()->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Leatherback')]),
            $this->animal(Sex::Weiblich, [$this->morph('Leatherback')]),
        );

        $phenotypes = $result->offspringPhenotypes();

        self::assertEqualsWithDelta(0.25, $phenotypes['Silkback'], 0.0001);
        self::assertEqualsWithDelta(0.50, $phenotypes['Leatherback'], 0.0001);
        self::assertEqualsWithDelta(0.25, $phenotypes[Phenotype::WILDTYPE_LABEL], 0.0001);

        // Tierschutzhinweis aus config/genetik.php — er haengt am Ergebnis,
        // nicht an der Eingabe.
        self::assertTrue($result->hasWarningOfType(GeneticWarning::TYPE_WELFARE));
    }

    // ------------------------------------------------------ unsichere Angaben

    public function testPossHetGehtMitSeinerWahrscheinlichkeitEin(): void
    {
        $result = $this->simulation()->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Hypomelanistic', Zygosity::PossHet66)]),
            $this->animal(Sex::Weiblich, [$this->morph('Hypomelanistic')]),
        );

        $phenotypes = $result->offspringPhenotypes();

        // Mit zwei Dritteln ist der Vater Traeger; dann faellt die Haelfte
        // sichtbar. 2/3 * 1/2 = 1/3.
        self::assertEqualsWithDelta(1 / 3, $phenotypes['Hypo'], 0.0001);
        self::assertEqualsWithDelta(2 / 3, $phenotypes['het Hypo'], 0.0001);
        self::assertTrue($result->hasWarningOfType(GeneticWarning::TYPE_UNCERTAIN));
    }

    // ---------------------------------------------------- geschlechtsgebunden

    /**
     * Der Fall, an dem sich die geschlechtsgebundene Vererbung zeigt: Dieselben
     * Merkmale, vertauschte Geschlechter — und ein anderes Ergebnis.
     */
    public function testGeschlechtsgebundenTrenntSoehneUndToechter(): void
    {
        $simulation = $this->simulation(['geschlechtssystem' => 'zw']);

        $vaterSichtbar = $simulation->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Ghost')]),
            $this->animal(Sex::Weiblich),
        );

        $bySex = $vaterSichtbar->offspringPhenotypesBySex();
        self::assertSame(['Ghost' => 1.0], $bySex[Sex::Weiblich->value]);
        self::assertSame(['het Ghost' => 1.0], $bySex[Sex::Maennlich->value]);

        $mutterSichtbar = $simulation->cross(
            $this->animal(Sex::Maennlich),
            $this->animal(Sex::Weiblich, [$this->morph('Ghost')]),
        );

        $bySex = $mutterSichtbar->offspringPhenotypesBySex();
        self::assertSame([Phenotype::WILDTYPE_LABEL => 1.0], $bySex[Sex::Weiblich->value]);
        self::assertSame(['het Ghost' => 1.0], $bySex[Sex::Maennlich->value]);
    }

    public function testGeschlechtsgebundeneFelderStehenGetrenntImBericht(): void
    {
        $result = $this->simulation(['geschlechtssystem' => 'zw'])->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Ghost')]),
            $this->animal(Sex::Weiblich),
        );

        $fields = $result->punnettFields();

        self::assertArrayHasKey('ghost:m', $fields);
        self::assertArrayHasKey('ghost:w', $fields);
        self::assertStringContainsString('Söhne', $fields['ghost:m']->label);
        self::assertStringContainsString('Töchter', $fields['ghost:w']->label);
    }

    public function testOhneGeschlechtsangabeWirdDieUnschaerfeGemeldet(): void
    {
        $result = $this->simulation(['geschlechtssystem' => 'zw'])->cross(
            $this->animal(Sex::Unbekannt, [$this->morph('Ghost', Zygosity::Het)]),
            $this->animal(Sex::Unbekannt, [$this->morph('Ghost', Zygosity::Het)]),
        );

        self::assertTrue($result->hasWarningOfType(GeneticWarning::TYPE_SEX));
    }

    // ----------------------------------------------------------------- letal

    public function testLetaleHomozygoteFormMindertDieErwarteteNachzucht(): void
    {
        $result = $this->simulation()->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Wobble')]),
            $this->animal(Sex::Weiblich, [$this->morph('Wobble')]),
        );

        self::assertEqualsWithDelta(0.25, $result->lethalShare(), 0.0001);
        self::assertEqualsWithDelta(0.75, $result->viabilityRate(), 0.0001);

        // Unter den lebensfaehigen Tieren bleiben zwei Drittel sichtbar.
        $phenotypes = $result->offspringPhenotypes();
        self::assertEqualsWithDelta(2 / 3, $phenotypes['Wobble'], 0.0001);
        self::assertEqualsWithDelta(1 / 3, $phenotypes[Phenotype::WILDTYPE_LABEL], 0.0001);

        $lethal = array_values(array_filter(
            $result->warnings(),
            static fn(GeneticWarning $warning): bool => $warning->type === GeneticWarning::TYPE_LETHAL,
        ));

        self::assertCount(1, $lethal);
        self::assertSame('fehler', $lethal[0]->severity->value);
        self::assertEqualsWithDelta(0.25, $lethal[0]->frequency ?? 0.0, 0.0001);

        // 20 Eier, 80 % Schlupfquote, davon drei Viertel lebensfaehig.
        self::assertSame(20, $result->expectedClutchSize());
        self::assertSame(12, $result->expectedOffspringCount());
    }

    /**
     * Letalkombinationen ueber zwei Genorte hinweg stehen in
     * config/genetik.php — der Merkmalskatalog kann sie nicht abbilden.
     */
    public function testLetalkombinationAusDerKonfiguration(): void
    {
        $simulation = $this->simulation([
            'letalkombinationen' => [
                [
                    'merkmale' => ['Dunner', 'Hypomelanistic'],
                    'anteil' => 1.0,
                    'hinweis' => 'Beispielkombination für den Test.',
                ],
            ],
        ]);

        $result = $simulation->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Dunner'), $this->morph('Hypomelanistic')]),
            $this->animal(Sex::Weiblich, [$this->morph('Hypomelanistic')]),
        );

        // Alle Nachkommen sind "Dunner Hypo" oder "Hypo"; die Haelfte traegt
        // Dunner und faellt damit aus.
        self::assertEqualsWithDelta(0.5, $result->lethalShare(), 0.0001);
        self::assertTrue($result->hasWarningOfType(GeneticWarning::TYPE_LETHAL));
    }

    public function testVollstaendigLetaleVerpaarungBrichtAb(): void
    {
        $simulation = $this->simulation([
            'letalkombinationen' => [
                ['merkmale' => ['Zero', 'Hypomelanistic'], 'anteil' => 1.0, 'hinweis' => 'Beispiel.'],
            ],
        ]);

        // Beide Elterntiere zeigen beide Merkmale und sind damit an beiden
        // Genorten reinerbig: Jeder Nachkomme traegt die Kombination.
        $this->expectException(LethalCrossException::class);

        $simulation->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Zero'), $this->morph('Hypomelanistic')]),
            $this->animal(Sex::Weiblich, [$this->morph('Zero'), $this->morph('Hypomelanistic')]),
        );
    }

    // -------------------------------------------------------------- Eingaben

    public function testVerschiedeneArtenWerdenAbgewiesen(): void
    {
        $andere = $this->speciesRepository->save(
            new Species(null, 'Eublepharis macularius', 'Leopardgecko', 'eublepharis-macularius'),
        );

        $this->expectException(IncompatibleSpeciesException::class);

        $this->simulation()->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Hypomelanistic')]),
            new BreedingAnimal($andere, Sex::Weiblich),
        );
    }

    public function testGleichesGeschlechtWirdGemeldet(): void
    {
        $result = $this->simulation()->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Hypomelanistic')]),
            $this->animal(Sex::Maennlich, [$this->morph('Hypomelanistic')]),
        );

        self::assertTrue($result->hasWarningOfType(GeneticWarning::TYPE_SEX));
    }

    /**
     * Eine het-Angabe auf einem dominanten Merkmal ergibt keinen Sinn. Die
     * Pruefung dafuer steht im Anzeigenassistenten — der Simulator uebernimmt
     * sie, statt sie ein zweites Mal zu schreiben.
     */
    public function testUnstimmigeAngabenAusDerMerkmalsauswahlWerdenUebernommen(): void
    {
        $result = $this->simulation()->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Dunner', Zygosity::Het)]),
            $this->animal(Sex::Weiblich),
        );

        self::assertTrue($result->hasWarningOfType(GeneticWarning::TYPE_UNCERTAIN));
    }

    public function testPolygeneMerkmaleWerdenAlsTendenzGefuehrt(): void
    {
        $result = $this->simulation()->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Red')]),
            $this->animal(Sex::Weiblich),
        );

        self::assertTrue($result->hasWarningOfType(GeneticWarning::TYPE_POLYGENIC));
        self::assertSame(['Red' => 1.0], $result->offspringPhenotypes());
    }

    // -------------------------------------------------------------- Ergebnis

    public function testErgebnisIstSerialisierbarUndWiederLesbar(): void
    {
        $result = $this->simulation()->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Leatherback'), $this->morph('Hypomelanistic', Zygosity::Het)]),
            $this->animal(Sex::Weiblich, [$this->morph('Leatherback')]),
        );

        $restored = SimulationResult::fromArray($result->toArray());

        self::assertSame($result->offspringPhenotypes(), $restored->offspringPhenotypes());
        self::assertSame($result->offspringGenotypes(), $restored->offspringGenotypes());
        self::assertSame($result->expectedOffspringCount(), $restored->expectedOffspringCount());
        self::assertSame($result->speciesName(), $restored->speciesName());
        self::assertCount(\count($result->warnings()), $restored->warnings());
        self::assertSame(
            $result->parentage()[0]->morphString,
            $restored->parentage()[0]->morphString,
        );
        self::assertSame(
            array_keys($result->punnettFields()),
            array_keys($restored->punnettFields()),
        );
    }

    public function testErgebnisIstUeberJsonHinwegStabil(): void
    {
        $result = $this->simulation()->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Zero', Zygosity::Het)]),
            $this->animal(Sex::Weiblich, [$this->morph('Zero', Zygosity::Het)]),
        );

        $json = json_encode($result->toArray(), \JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(
            $result->offspringPhenotypes(),
            SimulationResult::fromArray($decoded)->offspringPhenotypes(),
        );
    }

    /**
     * Dieselben Eltern ergeben dieselbe Verteilung. Ohne diese Zusage waere ein
     * gespeicherter Bericht nicht nachvollziehbar.
     */
    public function testSimulationIstIdempotent(): void
    {
        $simulation = $this->simulation();
        $first = $this->animal(Sex::Maennlich, [$this->morph('Hypomelanistic', Zygosity::PossHet50), $this->morph('Leatherback')]);
        $second = $this->animal(Sex::Weiblich, [$this->morph('Hypomelanistic', Zygosity::Het)]);

        self::assertSame(
            $simulation->cross($first, $second)->toArray(),
            $simulation->cross($first, $second)->toArray(),
        );
    }

    public function testEineSimulationBleibtDeutlichUnterHundertMillisekunden(): void
    {
        $simulation = $this->simulation();
        $first = $this->animal(Sex::Maennlich, [
            $this->morph('Hypomelanistic', Zygosity::Het),
            $this->morph('Zero', Zygosity::Het),
            $this->morph('Leatherback'),
            $this->morph('Dunner'),
        ]);
        $second = $this->animal(Sex::Weiblich, [
            $this->morph('Hypomelanistic', Zygosity::Het),
            $this->morph('Witblits', Zygosity::Het),
            $this->morph('Leatherback'),
        ]);

        $start = microtime(true);
        $simulation->cross($first, $second);
        $elapsedMs = (microtime(true) - $start) * 1000;

        self::assertLessThan(100.0, $elapsedMs, \sprintf('Simulation dauerte %.1f ms.', $elapsedMs));
    }

    public function testElterntiereStehenMitAngabeUndGenotypImErgebnis(): void
    {
        $result = $this->simulation()->cross(
            new BreedingAnimal(
                $this->speciesId,
                Sex::Maennlich,
                [$this->morph('Hypomelanistic'), $this->morph('Zero', Zygosity::Het)],
                listingId: 42,
                label: 'Anzeige #42',
            ),
            $this->animal(Sex::Weiblich),
        );

        [$first, $second] = $result->parentage();

        self::assertSame('Anzeige #42', $first->label);
        self::assertSame(42, $first->listingId);
        self::assertSame('Hypo het Zero', $first->morphString);
        self::assertSame(['hypomelanistic', 'hypomelanistic'], $first->genotype['hypomelanistic']);
        self::assertSame(['zero', '+'], $first->genotype['zero_witblits']);
        self::assertSame(Phenotype::WILDTYPE_LABEL, $second->morphString);
        self::assertNull($second->listingId);
    }

    public function testRegelwerkLaesstSichAustauschen(): void
    {
        $simulation = new CrossSimulation(
            $this->morphs,
            $this->speciesRepository,
            new GeneticsConfiguration(require \dirname(__DIR__, 3) . '/config/genetik.php'),
            $this->clock,
            rules: RuleRegistry::default(),
        );

        $result = $simulation->cross(
            $this->animal(Sex::Maennlich, [$this->morph('Hypomelanistic')]),
            $this->animal(Sex::Weiblich, [$this->morph('Hypomelanistic')]),
        );

        self::assertSame(['Hypo' => 1.0], $result->offspringPhenotypes());
    }
}
