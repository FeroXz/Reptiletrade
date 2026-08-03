<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Reptilienmarkt\Support\Translator;
use SplFileInfo;

#[CoversClass(Translator::class)]
final class TranslatorTest extends TestCase
{
    private function translator(): Translator
    {
        return new Translator(\dirname(__DIR__, 2) . '/lang');
    }

    public function testTexteKommenAusDemKatalog(): void
    {
        self::assertSame('Postfach', $this->translator()->translate('postfach.titel'));
    }

    public function testPlatzhalterWerdenErsetzt(): void
    {
        $text = $this->translator()->translate('schutz.rate_limit', ['minuten' => 15]);

        self::assertStringContainsString('15', $text);
        self::assertStringNotContainsString('{minuten}', $text);
    }

    /**
     * Ein fehlender Schluessel darf nicht zu einer leeren Seite fuehren —
     * er soll auffallen.
     */
    public function testFehlenderSchluesselKommtAlsSchluesselZurueck(): void
    {
        $translator = $this->translator();

        self::assertSame('gibt.es.nicht', $translator->translate('gibt.es.nicht'));
        self::assertContains('gibt.es.nicht', $translator->missingKeys());
    }

    public function testEinUndMehrzahl(): void
    {
        $translator = $this->translator();

        self::assertSame('1 ungelesene Nachricht', $translator->choose('postfach.ungelesen', 1));
        self::assertSame('3 ungelesene Nachrichten', $translator->choose('postfach.ungelesen', 3));
        self::assertSame('0 ungelesene Nachrichten', $translator->choose('postfach.ungelesen', 0));
    }

    public function testUnbekannteSpracheFaelltAufDeutschZurueck(): void
    {
        $translator = new Translator(\dirname(__DIR__, 2) . '/lang', 'en-GB');

        self::assertSame('Postfach', $translator->translate('postfach.titel'));
        self::assertSame(Translator::BASE_LOCALE, Translator::BASE_LOCALE);
    }

    public function testHasErkenntVorhandeneSchluessel(): void
    {
        $translator = $this->translator();

        self::assertTrue($translator->has('postfach.titel'));
        self::assertFalse($translator->has('postfach.gibt.es.nicht'));
    }

    /**
     * Der Katalog ist die einzige Quelle der Oberflaechentexte. Wenn hier
     * etwas fehlt oder leer ist, faellt es beim Bauen auf und nicht erst im
     * Browser.
     */
    public function testDerKatalogIstVollstaendigUndOhneLeereTexte(): void
    {
        /** @var array<string, string> $katalog */
        $katalog = require \dirname(__DIR__, 2) . '/lang/de-DE.php';

        self::assertNotEmpty($katalog);

        foreach ($katalog as $schluessel => $text) {
            self::assertIsString($schluessel);
            self::assertIsString($text);
            self::assertNotSame('', trim($text), 'Leerer Text bei: ' . $schluessel);
        }
    }

    /**
     * Jeder in den Templates verwendete Schluessel muss im Katalog stehen.
     */
    public function testAlleInTemplatesVerwendetenSchluesselExistieren(): void
    {
        $translator = $this->translator();
        $verzeichnis = \dirname(__DIR__, 2) . '/templates';

        $dateien = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($verzeichnis));
        $fehlend = [];

        foreach ($dateien as $datei) {
            if (!$datei instanceof SplFileInfo || $datei->getExtension() !== 'twig') {
                continue;
            }

            $inhalt = (string) file_get_contents($datei->getPathname());

            // t('schluessel') und tw('schluessel', ...)
            preg_match_all("/\\bt\\(\\s*'([^']+)'/", $inhalt, $einzahl);
            preg_match_all("/\\btw\\(\\s*'([^']+)'/", $inhalt, $mehrzahl);

            foreach ($einzahl[1] as $schluessel) {
                if (!$translator->has($schluessel)) {
                    $fehlend[] = $datei->getFilename() . ': ' . $schluessel;
                }
            }

            foreach ($mehrzahl[1] as $schluessel) {
                if (!$translator->has($schluessel . '.eins') || !$translator->has($schluessel . '.viele')) {
                    $fehlend[] = $datei->getFilename() . ': ' . $schluessel . ' (Mehrzahl)';
                }
            }
        }

        self::assertSame([], $fehlend, 'Im Katalog fehlen Schlüssel.');
    }
}
