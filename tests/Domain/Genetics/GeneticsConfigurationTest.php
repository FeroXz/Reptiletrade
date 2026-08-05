<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Genetics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Genetics\ClutchProfile;
use Reptilienmarkt\Domain\Genetics\GeneticsConfiguration;
use Reptilienmarkt\Domain\Genetics\GeneticsConfigurationException;
use Reptilienmarkt\Domain\Genetics\LethalCombo;
use Reptilienmarkt\Domain\Genetics\SexSystem;

#[CoversClass(GeneticsConfiguration::class)]
#[CoversClass(ClutchProfile::class)]
#[CoversClass(LethalCombo::class)]
#[CoversClass(SexSystem::class)]
final class GeneticsConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function config(array $overrides = []): GeneticsConfiguration
    {
        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 3) . '/config/genetik.php';

        return new GeneticsConfiguration(array_merge($config, $overrides));
    }

    public function testDieMitgelieferteKonfigurationIstLesbar(): void
    {
        $config = $this->config();

        self::assertSame(SexSystem::Zw, $config->sexSystem('pogona-vitticeps'));
        self::assertSame(['Silkback' => 'Leatherback'], $config->superForms('pogona-vitticeps'));
        self::assertArrayHasKey('Silkback', $config->welfareNotes('pogona-vitticeps'));
        self::assertSame([], $config->lethalCombos('pogona-vitticeps'));
        self::assertSame(20, $config->clutch('pogona-vitticeps')->size);
    }

    /**
     * Arten ohne eigenen Eintrag sind der Normalfall: Der Artenstamm hat 58
     * Arten, Merkmalskataloge gibt es nur fuer einen Teil davon.
     */
    public function testUnbekannteArtFaelltAufDieVoreinstellungZurueck(): void
    {
        $config = $this->config();

        self::assertSame(SexSystem::Keines, $config->sexSystem('varanus-acanthurus'));
        self::assertSame([], $config->superForms('varanus-acanthurus'));
        self::assertSame(10, $config->clutch('varanus-acanthurus')->size);
    }

    public function testTemperaturbestimmteArtenHabenKeineGeschlechtschromosomen(): void
    {
        $system = $this->config()->sexSystem('eublepharis-macularius');

        self::assertSame(SexSystem::Keines, $system);
        self::assertFalse($system->supportsSexLinkage());
        self::assertNull($system->hemizygousSex());
    }

    public function testDasGelegeErgibtDieErwartetenSchluepflinge(): void
    {
        $clutch = $this->config()->clutch('python-regius');

        self::assertSame(6, $clutch->size);
        self::assertEqualsWithDelta(5.1, $clutch->expectedHatchlings(), 0.0001);
    }

    // ------------------------------------------------------------- Schalter

    public function testAbgeschaltetIstAbgeschaltet(): void
    {
        $config = $this->config(['enabled' => false]);

        self::assertFalse($config->enabled());
        self::assertFalse($config->isEnabledFor(1));
    }

    /**
     * Die stufenweise Freischaltung haengt an der Konto-ID und nicht am Zufall:
     * Wer den Rechner einmal gesehen hat, sieht ihn beim naechsten Aufruf wieder.
     */
    public function testDieFreischaltungIstJeKontoStabil(): void
    {
        $config = $this->config(['enabled' => true, 'rollout_percentage' => 50]);

        for ($userId = 1; $userId <= 20; ++$userId) {
            self::assertSame(
                $config->isEnabledFor($userId),
                $config->isEnabledFor($userId),
                'Dieselbe Konto-ID muss dieselbe Antwort bekommen.',
            );
        }
    }

    public function testDieFreischaltungTrifftUngefaehrDenAnteil(): void
    {
        $config = $this->config(['enabled' => true, 'rollout_percentage' => 25]);

        $freigeschaltet = 0;
        for ($userId = 1; $userId <= 1000; ++$userId) {
            if ($config->isEnabledFor($userId)) {
                ++$freigeschaltet;
            }
        }

        self::assertGreaterThan(150, $freigeschaltet);
        self::assertLessThan(350, $freigeschaltet);
    }

    public function testOhneKontoGibtEsKeineTeilfreischaltung(): void
    {
        self::assertFalse($this->config(['rollout_percentage' => 50])->isEnabledFor(null));
        self::assertTrue($this->config(['rollout_percentage' => 100])->isEnabledFor(null));
    }

    // ------------------------------------------------------------ Fehlerfaelle

    public function testUnbekanntesGeschlechtssystemFaelltAuf(): void
    {
        $this->expectException(GeneticsConfigurationException::class);

        $this->config(['standard' => ['geschlechtssystem' => 'xz']])->sexSystem('irgendeine-art');
    }

    public function testEineUnmoeglicheSchlupfquoteFaelltAuf(): void
    {
        $this->expectException(GeneticsConfigurationException::class);

        $this->config(['standard' => ['gelege' => ['groesse' => 10, 'schlupfquote' => 1.5]]])->clutch('irgendeine-art');
    }

    public function testEineLetalkombinationBrauchtZweiMerkmale(): void
    {
        $this->expectException(GeneticsConfigurationException::class);

        $this->config([
            'standard' => ['letalkombinationen' => [['merkmale' => ['Nur eins'], 'anteil' => 1.0]]],
        ])->lethalCombos('irgendeine-art');
    }

    public function testEineLetalkombinationErkenntIhreMerkmale(): void
    {
        $combo = new LethalCombo(['Spider', 'Champagne'], 1.0, 'Beispiel.');

        self::assertTrue($combo->matches(['Pastel', 'Spider', 'Champagne']));
        self::assertFalse($combo->matches(['Spider']));
        self::assertFalse((new LethalCombo([], 1.0, ''))->matches([]));
    }

    public function testEinUnsinnigerRolloutWertFaelltAuf(): void
    {
        $this->expectException(GeneticsConfigurationException::class);

        $this->config(['rollout_percentage' => 140])->rolloutPercentage();
    }
}
