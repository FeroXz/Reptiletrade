<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Infra\Search\Fts5Query;

/**
 * Nutzereingaben duerfen niemals als FTS5-Syntax durchschlagen.
 */
#[CoversClass(Fts5Query::class)]
final class Fts5QueryTest extends TestCase
{
    public function testEinzelbegriffWirdZurPraefixsuche(): void
    {
        self::assertSame('"python"*', Fts5Query::fromUserInput('python'));
    }

    public function testMehrereBegriffeWerdenGequotet(): void
    {
        self::assertSame('"koenigs" "python"*', Fts5Query::fromUserInput('koenigs python'));
    }

    #[DataProvider('leereEingaben')]
    public function testLeereEingabeErgibtKeineAbfrage(string $eingabe): void
    {
        self::assertNull(Fts5Query::fromUserInput($eingabe));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function leereEingaben(): iterable
    {
        yield 'leer' => [''];

        yield 'nur Leerzeichen' => ['   '];

        yield 'nur Satzzeichen' => ['*** "" ()'];

        yield 'zu kurzer Begriff' => ['a'];
    }

    #[DataProvider('gefaehrlicheEingaben')]
    public function testSyntaxzeichenWerdenEntschaerft(string $eingabe): void
    {
        $abfrage = Fts5Query::fromUserInput($eingabe);

        // Entweder es bleibt gar nichts uebrig, oder das Ergebnis besteht
        // ausschliesslich aus vollstaendig gequoteten Phrasen.
        self::assertTrue(
            $abfrage === null || preg_match('/^("[^"]+" )*"[^"]+"\*$/', $abfrage) === 1,
            \sprintf('Eingabe "%s" ergab die unsichere Abfrage %s', $eingabe, var_export($abfrage, true)),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function gefaehrlicheEingaben(): iterable
    {
        yield 'Anfuehrungszeichen' => ['"python"'];

        yield 'Sternchen' => ['py*thon'];

        yield 'Operator NOT' => ['python NOT albino'];

        yield 'Operator OR' => ['python OR albino'];

        yield 'Klammern' => ['(python AND albino)'];

        yield 'Doppelpunkt-Spaltensuche' => ['title:python'];

        yield 'NEAR-Operator' => ['NEAR(python albino, 3)'];

        yield 'Bindestrich' => ['königs-python'];
    }

    /**
     * Umlaute und Gross-/Kleinschreibung bleiben unangetastet — beides loest
     * der FTS5-Tokenizer (unicode61, remove_diacritics 2) beim Indizieren auf.
     */
    public function testUmlauteUndGrossschreibungBleibenErhalten(): void
    {
        self::assertSame('"Königspython"*', Fts5Query::fromUserInput('Königspython'));
        self::assertSame('"Grüner" "Baumpython"*', Fts5Query::fromUserInput('Grüner Baumpython'));
    }

    public function testAnzahlDerBegriffeIstBegrenzt(): void
    {
        $abfrage = Fts5Query::fromUserInput('eins zwei drei vier fuenf sechs sieben acht neun zehn elf');

        self::assertNotNull($abfrage);
        self::assertSame(8, substr_count($abfrage, '"') / 2);
    }
}
