<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Trust;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Trust\ContactMasker;

#[CoversClass(ContactMasker::class)]
final class ContactMaskerTest extends TestCase
{
    private function masker(int $ersteN = 3): ContactMasker
    {
        return new ContactMasker(true, $ersteN);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emailVarianten(): iterable
    {
        yield 'schlicht' => ['Schreib mir an zuechter@example.tld, dann klappt das.'];
        yield 'at in Klammern' => ['Meine Adresse: zuechter (at) example.tld'];
        yield 'punkt ausgeschrieben' => ['zuechter@example punkt tld'];
        yield 'in Grossschreibung' => ['ZUECHTER@EXAMPLE.TLD'];
    }

    #[DataProvider('emailVarianten')]
    public function testEmailAdressenVerschwinden(string $text): void
    {
        $maskiert = $this->masker()->mask($text);

        self::assertStringNotContainsString('example.tld', $maskiert);
        self::assertStringContainsString(ContactMasker::PLACEHOLDER_EMAIL, $maskiert);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function telefonVarianten(): iterable
    {
        yield 'mit Vorwahl' => ['Ruf an: 0176 12345678'];
        yield 'international' => ['+49 176 12345678'];
        yield 'mit Schraegstrich' => ['089/1234567'];
        yield 'mit Bindestrichen' => ['0176-123-4567'];
        yield 'mit doppelter Null' => ['0049 176 12345678'];
    }

    #[DataProvider('telefonVarianten')]
    public function testTelefonnummernVerschwinden(string $text): void
    {
        self::assertStringContainsString(
            ContactMasker::PLACEHOLDER_PHONE,
            $this->masker()->mask($text),
        );
    }

    /**
     * Preise, Gewichte und Jahreszahlen sind keine Telefonnummern.
     *
     * @return iterable<string, array{string}>
     */
    public static function harmloseZahlen(): iterable
    {
        yield 'Preis' => ['Das Tier kostet 180 Euro.'];
        yield 'Gewicht' => ['Sie wiegt 210 g.'];
        yield 'Jahr' => ['Nachzucht 2026, geschlüpft im April.'];
        yield 'Postleitzahl' => ['Ich komme aus 80331 München.'];
    }

    #[DataProvider('harmloseZahlen')]
    public function testHarmloseZahlenBleibenStehen(string $text): void
    {
        self::assertSame($text, $this->masker()->mask($text));
    }

    public function testMaskierungGiltNurFuerDieErstenNachrichten(): void
    {
        $text = 'Ruf mich an: 0176 12345678';
        $masker = $this->masker(3);

        for ($sequence = 1; $sequence <= 3; ++$sequence) {
            self::assertNotSame($text, $masker->maskForSequence($text, $sequence), 'Nachricht ' . $sequence);
        }

        self::assertSame($text, $masker->maskForSequence($text, 4));
    }

    public function testAbgeschalteteMaskierungLaesstAllesDurch(): void
    {
        $text = 'zuechter@example.tld';

        self::assertSame($text, (new ContactMasker(false, 3))->maskForSequence($text, 1));
    }

    public function testErkennungOhneVeraenderung(): void
    {
        $masker = $this->masker();

        self::assertTrue($masker->containsContactData('Meine Nummer: 0176 12345678'));
        self::assertFalse($masker->containsContactData('Das Tier ist 210 g schwer.'));
    }
}
