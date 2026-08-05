<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Genetics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Genetics\AutosomalRule;
use Reptilienmarkt\Domain\Genetics\CodominantRule;
use Reptilienmarkt\Domain\Genetics\CrossContext;
use Reptilienmarkt\Domain\Genetics\DominantRule;
use Reptilienmarkt\Domain\Genetics\GeneticWarning;
use Reptilienmarkt\Domain\Genetics\Genotype;
use Reptilienmarkt\Domain\Genetics\LethalComboRule;
use Reptilienmarkt\Domain\Genetics\Locus;
use Reptilienmarkt\Domain\Genetics\NonHeritableRule;
use Reptilienmarkt\Domain\Genetics\PolygenicRule;
use Reptilienmarkt\Domain\Genetics\RecessiveRule;
use Reptilienmarkt\Domain\Genetics\RuleRegistry;
use Reptilienmarkt\Domain\Genetics\SexLinkedRule;
use Reptilienmarkt\Domain\Genetics\SexSystem;
use Reptilienmarkt\Domain\Listing\MorphSelection;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Listing\Zygosity;
use Reptilienmarkt\Domain\Species\Inheritance;
use Reptilienmarkt\Domain\Species\Morph;

/**
 * Jeder Erbgang einzeln: Kreuzung, Auspraegung und die Hinweise, die dazu
 * gehoeren.
 */
#[CoversClass(AutosomalRule::class)]
#[CoversClass(DominantRule::class)]
#[CoversClass(RecessiveRule::class)]
#[CoversClass(CodominantRule::class)]
#[CoversClass(SexLinkedRule::class)]
#[CoversClass(PolygenicRule::class)]
#[CoversClass(NonHeritableRule::class)]
#[CoversClass(LethalComboRule::class)]
#[CoversClass(RuleRegistry::class)]
#[CoversClass(Locus::class)]
final class InheritanceRuleTest extends TestCase
{
    private function morph(string $name, Inheritance $inheritance, bool $lethal = false, int $id = 1): Morph
    {
        return new Morph($id, 1, $name, $inheritance, [], null, $lethal);
    }

    /**
     * @param array<string, Morph> $morphs
     * @param array<string, Morph> $superForms
     */
    private function locus(string $id, Inheritance $inheritance, array $morphs, array $superForms = []): Locus
    {
        return new Locus($id, $inheritance, $morphs, $superForms);
    }

    private function context(Genotype $first, Genotype $second, Sex $offspring = Sex::Maennlich): CrossContext
    {
        return new CrossContext($first, Sex::Maennlich, $second, Sex::Weiblich, $offspring);
    }

    /**
     * @param list<MorphSelection> $expression
     *
     * @return list<string>
     */
    private function labels(array $expression): array
    {
        return array_map(
            static fn(MorphSelection $selection): string => $selection->zygosity === Zygosity::Visual
                ? $selection->morph->name
                : 'het ' . $selection->morph->name,
            $expression,
        );
    }

    // ------------------------------------------------------------- dominant

    public function testDominantZeigtSichMitEinemAllel(): void
    {
        $rule = new DominantRule();
        $locus = $this->locus('dunner', Inheritance::Dominant, ['dunner' => $this->morph('Dunner', Inheritance::Dominant)]);

        self::assertSame(['Dunner'], $this->labels($rule->express($locus, ['dunner', '+'], Sex::Unbekannt)));
        self::assertSame(['Dunner'], $this->labels($rule->express($locus, ['dunner', 'dunner'], Sex::Unbekannt)));
        self::assertSame([], $rule->express($locus, ['+', '+'], Sex::Unbekannt));
    }

    public function testDominantHomozygotMalWildtypGibtNurSichtbare(): void
    {
        $rule = new DominantRule();
        $locus = $this->locus('dunner', Inheritance::Dominant, ['dunner' => $this->morph('Dunner', Inheritance::Dominant)]);

        $punnett = $rule->cross($locus, $this->context(
            new Genotype(['dunner' => ['dunner', 'dunner']]),
            new Genotype(['dunner' => ['+', '+']]),
        ));

        self::assertSame(['dunner/+' => 1.0], $punnett->distribution());
    }

    // ------------------------------------------------------------ rezessiv

    public function testRezessivZeigtSichNurReinerbig(): void
    {
        $rule = new RecessiveRule();
        $locus = $this->locus('hypo', Inheritance::Recessive, ['hypo' => $this->morph('Hypo', Inheritance::Recessive)]);

        self::assertSame(['Hypo'], $this->labels($rule->express($locus, ['hypo', 'hypo'], Sex::Unbekannt)));
        self::assertSame(['het Hypo'], $this->labels($rule->express($locus, ['hypo', '+'], Sex::Unbekannt)));
        self::assertSame([], $rule->express($locus, ['+', '+'], Sex::Unbekannt));
    }

    public function testZweiTraegerErgebenEinViertelSichtbare(): void
    {
        $rule = new RecessiveRule();
        $locus = $this->locus('hypo', Inheritance::Recessive, ['hypo' => $this->morph('Hypo', Inheritance::Recessive)]);

        $distribution = $rule->cross($locus, $this->context(
            new Genotype(['hypo' => ['hypo', '+']]),
            new Genotype(['hypo' => ['hypo', '+']]),
        ))->distribution();

        self::assertEqualsWithDelta(0.25, $distribution['hypo/hypo'], 0.0001);
        self::assertEqualsWithDelta(0.50, $distribution['hypo/+'], 0.0001);
        self::assertEqualsWithDelta(0.25, $distribution['+/+'], 0.0001);
    }

    /**
     * Zwei Anlagen desselben Genorts ergeben keine Traeger, sondern eine
     * sichtbare Mischform.
     */
    public function testAllelischeMerkmaleZeigenSichZusammen(): void
    {
        $rule = new RecessiveRule();
        $locus = $this->locus('zero_witblits', Inheritance::Recessive, [
            'zero' => $this->morph('Zero', Inheritance::Recessive, id: 1),
            'witblits' => $this->morph('Witblits', Inheritance::Recessive, id: 2),
        ]);

        self::assertSame(
            ['Witblits', 'Zero'],
            $this->labels($rule->express($locus, ['witblits', 'zero'], Sex::Unbekannt)),
        );

        $warnings = $rule->warnings($locus, $this->context(
            new Genotype(['zero_witblits' => ['zero', 'zero']]),
            new Genotype(['zero_witblits' => ['witblits', 'witblits']]),
        ));

        self::assertCount(1, $warnings);
        self::assertSame(GeneticWarning::TYPE_ALLELIC, $warnings[0]->type);
    }

    // -------------------------------------------------- unvollstaendig dominant

    public function testSuperformEntstehtAusZweiAnlagen(): void
    {
        $rule = new CodominantRule();
        $locus = $this->locus(
            'leatherback',
            Inheritance::IncompleteDominant,
            ['leatherback' => $this->morph('Leatherback', Inheritance::IncompleteDominant, id: 1)],
            ['leatherback' => $this->morph('Silkback', Inheritance::IncompleteDominant, id: 2)],
        );

        self::assertSame(['Leatherback'], $this->labels($rule->express($locus, ['leatherback', '+'], Sex::Unbekannt)));
        self::assertSame(['Silkback'], $this->labels($rule->express($locus, ['leatherback', 'leatherback'], Sex::Unbekannt)));

        $warnings = $rule->warnings($locus, $this->context(
            new Genotype(['leatherback' => ['leatherback', '+']]),
            new Genotype(['leatherback' => ['leatherback', '+']]),
        ));

        self::assertCount(1, $warnings);
        self::assertStringContainsString('Silkback', $warnings[0]->message);
    }

    public function testOhneHinterlegteSuperformBleibtDieBasisform(): void
    {
        $rule = new CodominantRule();
        $locus = $this->locus('pastel', Inheritance::IncompleteDominant, ['pastel' => $this->morph('Pastel', Inheritance::IncompleteDominant)]);

        self::assertSame(['Pastel'], $this->labels($rule->express($locus, ['pastel', 'pastel'], Sex::Unbekannt)));
        self::assertSame([], $rule->warnings($locus, $this->context(
            new Genotype(['pastel' => ['pastel', '+']]),
            new Genotype(['pastel' => ['pastel', '+']]),
        )));
    }

    // ------------------------------------------------------ geschlechtsgebunden

    /**
     * ZW-System: Toechter bekommen ihr Z vom Vater. Aus einem sichtbaren Vater
     * fallen deshalb ausnahmslos sichtbare Toechter — und Soehne, die nur
     * Traeger sind.
     */
    public function testSichtbarerVaterVererbtAnAlleToechter(): void
    {
        $rule = new SexLinkedRule();
        $locus = $this->locus('zero', Inheritance::SexLinked, ['zero' => $this->morph('Zero', Inheritance::SexLinked)]);

        $father = new Genotype(['zero' => ['zero', 'zero']]);
        $mother = new Genotype(['zero' => ['+']]);

        $daughters = $rule->cross($locus, new CrossContext(
            $father,
            Sex::Maennlich,
            $mother,
            Sex::Weiblich,
            Sex::Weiblich,
            SexSystem::Zw,
        ));

        self::assertSame(['zero' => 1.0], $daughters->distribution());
        self::assertSame(['Zero'], $this->labels($rule->express($locus, ['zero'], Sex::Weiblich)));

        $sons = $rule->cross($locus, new CrossContext(
            $father,
            Sex::Maennlich,
            $mother,
            Sex::Weiblich,
            Sex::Maennlich,
            SexSystem::Zw,
        ));

        self::assertSame(['zero/+' => 1.0], $sons->distribution());
        self::assertSame(['het Zero'], $this->labels($rule->express($locus, ['zero', '+'], Sex::Maennlich)));
    }

    /**
     * Die Gegenprobe: Aus einer sichtbaren Mutter faellt kein sichtbarer
     * Nachkomme — ein gewoehnliches Punnett-Quadrat wuerde hier dasselbe
     * Ergebnis liefern wie oben und damit falsch liegen.
     */
    public function testSichtbareMutterVererbtNurAnSoehneUndDortNurAlsAnlage(): void
    {
        $rule = new SexLinkedRule();
        $locus = $this->locus('zero', Inheritance::SexLinked, ['zero' => $this->morph('Zero', Inheritance::SexLinked)]);

        $father = new Genotype(['zero' => ['+', '+']]);
        $mother = new Genotype(['zero' => ['zero']]);

        $sons = $rule->cross($locus, new CrossContext($father, Sex::Maennlich, $mother, Sex::Weiblich, Sex::Maennlich, SexSystem::Zw));
        $daughters = $rule->cross($locus, new CrossContext($father, Sex::Maennlich, $mother, Sex::Weiblich, Sex::Weiblich, SexSystem::Zw));

        self::assertSame(['zero/+' => 1.0], $sons->distribution());
        self::assertSame(['+' => 1.0], $daughters->distribution());
    }

    public function testOhneGeschlechtsangabeWirdGewarnt(): void
    {
        $rule = new SexLinkedRule();
        $locus = $this->locus('zero', Inheritance::SexLinked, ['zero' => $this->morph('Zero', Inheritance::SexLinked)]);

        $warnings = $rule->warnings($locus, new CrossContext(
            new Genotype(['zero' => ['zero', '+']]),
            Sex::Unbekannt,
            new Genotype(['zero' => ['+']]),
            Sex::Unbekannt,
            Sex::Maennlich,
            SexSystem::Zw,
        ));

        self::assertCount(1, $warnings);
        self::assertSame(GeneticWarning::TYPE_SEX, $warnings[0]->type);
        self::assertSame('warnung', $warnings[0]->severity->value);
    }

    public function testArtOhneGeschlechtschromosomenWirdGewarnt(): void
    {
        $rule = new SexLinkedRule();
        $locus = $this->locus('zero', Inheritance::SexLinked, ['zero' => $this->morph('Zero', Inheritance::SexLinked)]);

        $warnings = $rule->warnings($locus, new CrossContext(
            new Genotype(['zero' => ['zero', '+']]),
            Sex::Maennlich,
            new Genotype(['zero' => ['+', '+']]),
            Sex::Weiblich,
            Sex::Maennlich,
            SexSystem::Keines,
        ));

        self::assertCount(1, $warnings);
        self::assertStringContainsString('Geschlechtschromosomen-System', $warnings[0]->message);
    }

    // --------------------------------------------------------------- polygen

    public function testPolygenWarntVorDerScheingenauigkeit(): void
    {
        $rule = new PolygenicRule();
        $locus = $this->locus('red', Inheritance::LineBred, ['red' => $this->morph('Red', Inheritance::LineBred)]);

        self::assertSame(['Red'], $this->labels($rule->express($locus, ['red', '+'], Sex::Unbekannt)));

        $warnings = $rule->warnings($locus, $this->context(
            new Genotype(['red' => ['red', 'red']]),
            new Genotype(['red' => ['+', '+']]),
        ));

        self::assertCount(1, $warnings);
        self::assertSame(GeneticWarning::TYPE_POLYGENIC, $warnings[0]->type);
    }

    public function testParadoxWirdNichtVererbt(): void
    {
        $rule = new NonHeritableRule();
        $locus = $this->locus('paradox', Inheritance::Paradox, ['paradox' => $this->morph('Paradox', Inheritance::Paradox)]);

        self::assertSame([], $rule->express($locus, ['paradox', 'paradox'], Sex::Unbekannt));

        $warnings = $rule->warnings($locus, $this->context(
            new Genotype(['paradox' => ['paradox', 'paradox']]),
            new Genotype(['paradox' => ['+', '+']]),
        ));

        self::assertSame(GeneticWarning::TYPE_NOT_HERITABLE, $warnings[0]->type);
    }

    // ------------------------------------------------------------------ letal

    public function testLetaleHomozygoteFormWirdGekennzeichnetUndGemeldet(): void
    {
        $locus = $this->locus('spider', Inheritance::Dominant, ['spider' => $this->morph('Spider', Inheritance::Dominant, lethal: true)]);
        $rule = new LethalComboRule(new DominantRule());

        $context = $this->context(
            new Genotype(['spider' => ['spider', '+']]),
            new Genotype(['spider' => ['spider', '+']]),
        );

        $punnett = $rule->cross($locus, $context);
        self::assertTrue($punnett->isLethal('spider/spider'));
        self::assertEqualsWithDelta(0.25, $punnett->lethalShare(), 0.0001);

        $warnings = $rule->warnings($locus, $context);
        self::assertCount(1, $warnings);
        self::assertSame(GeneticWarning::TYPE_LETHAL, $warnings[0]->type);
        self::assertSame('fehler', $warnings[0]->severity->value);
        self::assertEqualsWithDelta(0.25, $warnings[0]->frequency ?? 0.0, 0.0001);
        self::assertStringContainsString('Spider', $warnings[0]->message);
    }

    public function testOhneLetalenPartnerBleibtDieVerteilungUnberuehrt(): void
    {
        $locus = $this->locus('spider', Inheritance::Dominant, ['spider' => $this->morph('Spider', Inheritance::Dominant, lethal: true)]);
        $rule = new LethalComboRule(new DominantRule());

        $punnett = $rule->cross($locus, $this->context(
            new Genotype(['spider' => ['spider', '+']]),
            new Genotype(['spider' => ['+', '+']]),
        ));

        self::assertSame(0.0, $punnett->lethalShare());
        self::assertSame([], $rule->warnings($locus, $this->context(
            new Genotype(['spider' => ['spider', '+']]),
            new Genotype(['spider' => ['+', '+']]),
        )));
    }

    // --------------------------------------------------------------- Zuordnung

    public function testRegistryWaehltDenPassendenErbgang(): void
    {
        $registry = RuleRegistry::default();

        self::assertInstanceOf(DominantRule::class, $registry->ruleFor(
            $this->locus('dunner', Inheritance::Dominant, ['dunner' => $this->morph('Dunner', Inheritance::Dominant)]),
        ));

        self::assertInstanceOf(RecessiveRule::class, $registry->ruleFor(
            $this->locus('hypo', Inheritance::Recessive, ['hypo' => $this->morph('Hypo', Inheritance::Recessive)]),
        ));

        self::assertInstanceOf(SexLinkedRule::class, $registry->ruleFor(
            $this->locus('zero', Inheritance::SexLinked, ['zero' => $this->morph('Zero', Inheritance::SexLinked)]),
        ));
    }

    public function testRegistryLegtDieLetalpruefungUm(): void
    {
        $registry = RuleRegistry::default();
        $locus = $this->locus('spider', Inheritance::Dominant, ['spider' => $this->morph('Spider', Inheritance::Dominant, lethal: true)]);

        self::assertInstanceOf(LethalComboRule::class, $registry->ruleFor($locus));
    }
}
