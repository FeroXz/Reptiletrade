<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Genetics;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Genetics\BreedingAnimal;
use Reptilienmarkt\Domain\Genetics\CrossSimulation;
use Reptilienmarkt\Domain\Genetics\GeneticsConfiguration;
use Reptilienmarkt\Domain\Genetics\SimulationResult;
use Reptilienmarkt\Domain\Listing\MorphSelection;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Species\Inheritance;
use Reptilienmarkt\Domain\Species\Morph;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Http\View\TwigFactory;
use Reptilienmarkt\Infra\Genetics\PdfDocument;
use Reptilienmarkt\Infra\Genetics\PdfReportGenerator;
use Reptilienmarkt\Infra\Persistence\PdoMorphRepository;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Support\Translator;
use Reptilienmarkt\Tests\DatabaseTestCase;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(PdfReportGenerator::class)]
#[CoversClass(PdfDocument::class)]
final class PdfReportGeneratorTest extends DatabaseTestCase
{
    private PdfReportGenerator $generator;

    private CrossSimulation $simulation;

    private int $speciesId;

    /** @var array<string, Morph> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $morphs = new PdoMorphRepository($this->database);
        $species = new PdoSpeciesRepository($this->database);

        $this->speciesId = $species->save(new Species(null, 'Pogona vitticeps', 'Bartagame', 'pogona-vitticeps'));

        foreach (
            [
                ['Hypomelanistic', Inheritance::Recessive, null, false],
                ['Leatherback', Inheritance::IncompleteDominant, 'leatherback', false],
                ['Silkback', Inheritance::IncompleteDominant, 'leatherback', false],
                ['Wobble', Inheritance::Dominant, null, true],
            ] as [$name, $inheritance, $group, $lethal]
        ) {
            $id = $morphs->save(new Morph(null, $this->speciesId, $name, $inheritance, [], $group, $lethal));
            $this->catalog[$name] = $morphs->findById($id) ?? self::fail('Merkmal fehlt.');
        }

        /** @var array<string, mixed> $config */
        $config = require \dirname(__DIR__, 3) . '/config/genetik.php';

        $this->simulation = new CrossSimulation(
            $morphs,
            $species,
            new GeneticsConfiguration($config),
            new FrozenClock(new DateTimeImmutable('2026-08-05T12:00:00+00:00')),
        );

        $translator = new Translator(\dirname(__DIR__, 3) . '/lang');
        $this->generator = new PdfReportGenerator(
            TwigFactory::create(\dirname(__DIR__, 3) . '/templates', false, null, $translator),
        );
    }

    private function ergebnis(string $morph = 'Leatherback'): SimulationResult
    {
        return $this->simulation->cross(
            new BreedingAnimal($this->speciesId, Sex::Maennlich, [new MorphSelection($this->catalog[$morph])]),
            new BreedingAnimal($this->speciesId, Sex::Weiblich, [new MorphSelection($this->catalog[$morph])]),
        );
    }

    public function testHtmlEnthaeltEindeutigeVerteilungUndWarnungen(): void
    {
        $html = $this->generator->generateHtml($this->ergebnis());

        self::assertStringContainsString('Genetik-Bericht', $html);
        self::assertStringContainsString('Bartagame', $html);
        self::assertStringContainsString('Silkback', $html);
        self::assertStringContainsString('25,0 %', $html);
        self::assertStringContainsString('Punnett', $html);
        // Der Tierschutzhinweis aus config/genetik.php steht im Bericht.
        self::assertStringContainsString('keine Schuppen', $html);
    }

    public function testHtmlKennzeichnetNichtLebensfaehigeFelder(): void
    {
        $html = $this->generator->generateHtml($this->ergebnis('Wobble'));

        self::assertStringContainsString('letal', $html);
        self::assertStringContainsString('nicht lebensfähig', $html);
    }

    public function testPdfIstEinGueltigesDokument(): void
    {
        $pdf = $this->generator->generatePdf($this->ergebnis());

        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringEndsWith("%%EOF\n", $pdf);
        self::assertStringContainsString('/Type /Catalog', $pdf);
        self::assertStringContainsString('/Type /Pages', $pdf);
        self::assertStringContainsString('/Type /Page ', $pdf);
        self::assertStringContainsString('/BaseFont /Helvetica', $pdf);
        self::assertStringContainsString('xref', $pdf);
        self::assertStringContainsString('startxref', $pdf);
    }

    /**
     * Die Verweistabelle muss auf die tatsaechlichen Byte-Positionen der
     * Objekte zeigen — sonst oeffnet kein Betrachter die Datei.
     */
    public function testDieVerweistabelleZeigtAufDieObjekte(): void
    {
        $pdf = $this->generator->generatePdf($this->ergebnis());

        preg_match('/startxref\n(\d+)\n/', $pdf, $treffer);
        self::assertArrayHasKey(1, $treffer);

        $xrefOffset = (int) $treffer[1];
        self::assertSame('xref', substr($pdf, $xrefOffset, 4));

        preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $eintraege);
        self::assertNotEmpty($eintraege[1]);

        foreach ($eintraege[1] as $offset) {
            $position = (int) $offset;
            self::assertMatchesRegularExpression(
                '/^\d+ 0 obj/',
                substr($pdf, $position, 20),
                'Der Verweis zeigt nicht auf den Objektanfang.',
            );
        }
    }

    /**
     * Umlaute muessen als Windows-1252 im Inhaltsstrom stehen, sonst zeigt der
     * Betrachter Buchstabensalat.
     */
    public function testUmlauteWerdenNachWindows1252Gewandelt(): void
    {
        $pdf = $this->generator->generatePdf($this->ergebnis());

        self::assertStringContainsString('/Encoding /WinAnsiEncoding', $pdf);
        // "lebensfähige" — das ä als einzelnes Byte 0xE4, nicht als UTF-8-Paar.
        self::assertStringContainsString('lebensf' . \chr(0xE4) . 'hige', $pdf);
        self::assertStringNotContainsString('lebensfähige', $pdf);
    }

    public function testLangeBerichteBekommenWeitereSeiten(): void
    {
        $document = new PdfDocument('Test');

        for ($i = 0; $i < 200; ++$i) {
            $document->paragraph('Zeile ' . $i);
        }

        $pdf = $document->render();

        preg_match('/\/Count (\d+)/', $pdf, $treffer);

        self::assertArrayHasKey(1, $treffer);
        self::assertGreaterThan(1, (int) $treffer[1]);
    }

    public function testDerBerichtLaesstSichAusDemGespeichertenJsonWiederAufbauen(): void
    {
        $result = $this->ergebnis();
        $json = json_encode($result->toArray(), \JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $wiederhergestellt = SimulationResult::fromArray($decoded);

        // Der PDF-Weg laeuft im Betrieb ueber genau diesen Umweg: Der Bericht
        // kommt aus der Datenbank, nicht aus einer frischen Rechnung.
        self::assertStringStartsWith('%PDF-1.4', $this->generator->generatePdf($wiederhergestellt));
        self::assertStringContainsString('Silkback', $this->generator->generateHtml($wiederhergestellt));
    }
}
