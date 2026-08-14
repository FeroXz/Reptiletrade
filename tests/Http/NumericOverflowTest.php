<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Listing\Listing;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Search\FacetCounts;
use Reptilienmarkt\Domain\Search\SearchCriteria;
use Reptilienmarkt\Domain\Search\SearchResult;
use Reptilienmarkt\Http\Message\Request;

/**
 * Zahlen aus der Adresse, die zu gross sind.
 *
 * PHP rechnet bei einem Ueberlauf stillschweigend in Fliesskomma weiter. Aus
 * (Seite - 1) * Treffer je Seite wird dann ein Float, und die naechste Methode
 * mit int-Rueckgabetyp bricht unter strict_types mit einem TypeError ab. Eine
 * Adresse wie /markt/?seite=99999999999999999999 war damit ein Serverfehler,
 * den jeder ohne Anmeldung ausloesen konnte — und der im Fehlerprotokoll
 * landete.
 *
 * Die Grenzen kosten nichts: Jenseits von Request::MAX_PAGE steht ohnehin kein
 * Treffer, und ueber Listing::MAX_PRICE_CENTS kein Tier.
 */
#[CoversClass(Request::class)]
#[CoversClass(SearchCriteria::class)]
#[CoversClass(SearchResult::class)]
#[CoversClass(Listing::class)]
final class NumericOverflowTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function ueberlaufWerte(): array
    {
        return [
            ['99999999999999999999'],
            ['9223372036854775807'],
            ['1e30'],
        ];
    }

    #[DataProvider('ueberlaufWerte')]
    public function testEineUnsinnigeSeitenzahlBleibtEineZahl(string $eingabe): void
    {
        $request = new Request('GET', '/markt/', ['seite' => $eingabe]);

        $seite = $request->queryPage();

        self::assertSame(Request::MAX_PAGE, $seite);
    }

    public function testDieSeitenzahlBleibtSonstUnveraendert(): void
    {
        self::assertSame(7, (new Request('GET', '/markt/', ['seite' => '7']))->queryPage());
        self::assertSame(1, (new Request('GET', '/markt/', ['seite' => '0']))->queryPage());
        self::assertSame(1, (new Request('GET', '/markt/', ['seite' => '-3']))->queryPage());
        self::assertSame(1, (new Request('GET', '/markt/'))->queryPage());
    }

    #[DataProvider('ueberlaufWerte')]
    public function testDerVersatzBleibtEinIntegerAuchBeiUnsinnigerSeite(string $eingabe): void
    {
        // Kriterien entstehen auch aus gespeicherten Suchen, nicht nur aus der
        // Adresse — deshalb deckelt SearchCriteria selbst noch einmal. Ohne die
        // Deckelung wirft der Aufruf einen TypeError, statt einen Wert zu
        // liefern.
        $criteria = new SearchCriteria(page: (int) $eingabe);

        self::assertSame((SearchCriteria::MAX_PAGE - 1) * $criteria->limit(), $criteria->offset());
    }

    public function testDieBlaetterleisteZeigtKeineSeiteAusDerNichtGelesenWurde(): void
    {
        $criteria = new SearchCriteria(page: \PHP_INT_MAX);
        $result = new SearchResult([], 0, new FacetCounts(), $criteria);

        self::assertSame(SearchCriteria::MAX_PAGE, $result->page());
    }

    /**
     * @return list<array{string, int|null}>
     */
    public static function preisEingaben(): array
    {
        return [
            ['', null],
            ['keine Zahl', null],
            ['0', 0],
            ['12,50', 1250],
            ['12.50', 1250],
            // Negativ ergibt keinen Preis, sondern null Euro — verschenkt wird
            // hier nichts, aber ein Minus in der Liste ist kein Preis.
            ['-20', 0],
            // Der Ueberlauf: (int) round(1e32) ist irgendeine Zahl, im Zweifel
            // eine grosse negative.
            ['1e30', Listing::MAX_PRICE_CENTS],
            ['999999999', Listing::MAX_PRICE_CENTS],
        ];
    }

    #[DataProvider('preisEingaben')]
    public function testDerPreisAusDemFormularBleibtImRahmen(string $eingabe, ?int $erwartet): void
    {
        $cents = Listing::priceCentsFromInput($eingabe);

        self::assertSame($erwartet, $cents);

        if ($cents !== null) {
            self::assertGreaterThanOrEqual(0, $cents);
            self::assertLessThanOrEqual(Listing::MAX_PRICE_CENTS, $cents);
        }
    }

    public function testDerPreisGehoertZurAnzeigeUndNichtNurZumFormular(): void
    {
        $listing = new Listing(
            null,
            1,
            ListingType::Verkauf,
            1,
            'Testanzeige',
            'Beschreibung',
            Listing::priceCentsFromInput('1e30'),
        );

        self::assertSame(Listing::MAX_PRICE_CENTS, $listing->priceCents);
    }
}
