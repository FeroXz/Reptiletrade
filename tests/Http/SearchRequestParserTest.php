<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Reptilienmarkt\Domain\Search\SearchCriteria;
use Reptilienmarkt\Http\Message\Request;
use Reptilienmarkt\Http\Search\SearchRequestParser;
use Reptilienmarkt\Infra\Persistence\PdoMorphRepository;
use Reptilienmarkt\Infra\Persistence\PdoPostalCodeRepository;
use Reptilienmarkt\Infra\Persistence\PdoSpeciesRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;

/**
 * Der Weg von der Adresse zu den Suchkriterien — mit den Werten, die niemand
 * eintippt und jeder Roboter irgendwann probiert.
 *
 * Der Preisfilter kommt in Euro herein und wird mit 100 multipliziert. Ohne
 * Obergrenze kippt dieses Produkt bei einer Zahl nahe PHP_INT_MAX in
 * Fliesskomma, und der int-Rueckgabetyp bricht mit einem TypeError ab: Aus
 * /markt/?preis_max=99999999999999999999 wurde so ein Serverfehler.
 */
#[CoversClass(SearchRequestParser::class)]
#[CoversClass(SearchCriteria::class)]
final class SearchRequestParserTest extends DatabaseTestCase
{
    private function parser(): SearchRequestParser
    {
        return new SearchRequestParser(
            new PdoSpeciesRepository($this->database),
            new PdoMorphRepository($this->database),
            new PdoPostalCodeRepository($this->database),
            $this->database,
        );
    }

    /**
     * @param array<string, string> $query
     */
    private function kriterien(array $query): SearchCriteria
    {
        return $this->parser()->parse(new Request('GET', '/markt/', $query))->criteria;
    }

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
    public function testEinUnsinnigerHoechstpreisErgibtEineGedeckelteZahl(string $eingabe): void
    {
        $criteria = $this->kriterien(['preis_max' => $eingabe]);

        self::assertNotNull($criteria->priceMaxCents);
        self::assertGreaterThan(0, $criteria->priceMaxCents);
    }

    #[DataProvider('ueberlaufWerte')]
    public function testEinUnsinnigerMindestpreisErgibtEineGedeckelteZahl(string $eingabe): void
    {
        $criteria = $this->kriterien(['preis_min' => $eingabe]);

        self::assertNotNull($criteria->priceMinCents);
        self::assertGreaterThan(0, $criteria->priceMinCents);
    }

    #[DataProvider('ueberlaufWerte')]
    public function testEineUnsinnigeSeiteErgibtEinenGedeckeltenVersatz(string $eingabe): void
    {
        $criteria = $this->kriterien(['seite' => $eingabe]);

        self::assertSame(Request::MAX_PAGE, $criteria->page);
        self::assertSame((SearchCriteria::MAX_PAGE - 1) * $criteria->limit(), $criteria->offset());
    }

    public function testGewoehnlichePreiseWerdenUnveraendertUmgerechnet(): void
    {
        $criteria = $this->kriterien(['preis_min' => '50', 'preis_max' => '500']);

        self::assertSame(5000, $criteria->priceMinCents);
        self::assertSame(50000, $criteria->priceMaxCents);
    }

    public function testNegativePreiseGeltenAlsKeinFilter(): void
    {
        $criteria = $this->kriterien(['preis_min' => '-10', 'preis_max' => '-1']);

        self::assertNull($criteria->priceMinCents);
        self::assertNull($criteria->priceMaxCents);
    }
}
