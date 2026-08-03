<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Geo\Coordinates;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Search\MorphFilter;
use Reptilienmarkt\Domain\Search\RadiusFilter;
use Reptilienmarkt\Domain\Search\SearchCriteria;
use Reptilienmarkt\Domain\Search\SearchRadius;
use Reptilienmarkt\Domain\Search\SortOrder;

#[CoversClass(SearchCriteria::class)]
#[CoversClass(SortOrder::class)]
#[CoversClass(SearchRadius::class)]
#[CoversClass(MorphFilter::class)]
final class SearchCriteriaTest extends TestCase
{
    private function radius(): RadiusFilter
    {
        return new RadiusFilter(new Coordinates(48.13743, 11.57549), SearchRadius::Km50, '80331');
    }

    public function testOhneFilterGiltNichtsAlsGefiltert(): void
    {
        self::assertFalse((new SearchCriteria())->isFiltered());
        self::assertTrue((new SearchCriteria(sexes: [Sex::Weiblich]))->isFiltered());
        self::assertTrue((new SearchCriteria(withImageOnly: true))->isFiltered());
    }

    public function testLeererSuchbegriffZaehltNicht(): void
    {
        self::assertFalse((new SearchCriteria(query: '   '))->hasQuery());
        self::assertFalse((new SearchCriteria(query: '   '))->isFiltered());
        self::assertTrue((new SearchCriteria(query: 'python'))->hasQuery());
    }

    public function testEntfernungssortierungBrauchtEinenStandort(): void
    {
        $ohne = new SearchCriteria(sort: SortOrder::Entfernung);
        $mit = new SearchCriteria(radius: $this->radius(), sort: SortOrder::Entfernung);

        self::assertSame(SortOrder::Neueste, $ohne->effectiveSort());
        self::assertSame(SortOrder::Entfernung, $mit->effectiveSort());
    }

    public function testRelevanzsortierungBrauchtEinenSuchbegriff(): void
    {
        self::assertSame(SortOrder::Neueste, (new SearchCriteria(sort: SortOrder::Relevanz))->effectiveSort());
        self::assertSame(SortOrder::Relevanz, (new SearchCriteria(query: 'hypo', sort: SortOrder::Relevanz))->effectiveSort());
    }

    public function testSeitenrechnung(): void
    {
        self::assertSame(0, (new SearchCriteria(perPage: 24))->offset());
        self::assertSame(48, (new SearchCriteria(page: 3, perPage: 24))->offset());
        self::assertSame(0, (new SearchCriteria(page: 0, perPage: 24))->offset(), 'Seite 0 wird auf Seite 1 gezogen.');
    }

    public function testSeitengroesseIstGedeckelt(): void
    {
        self::assertSame(SearchCriteria::MAX_PER_PAGE, (new SearchCriteria(perPage: 5000))->limit());
        self::assertSame(1, (new SearchCriteria(perPage: 0))->limit());
    }

    public function testWithKopiertUndAendertNurDasAngegebene(): void
    {
        $original = new SearchCriteria(
            query: 'hypo',
            speciesId: 7,
            sexes: [Sex::Weiblich],
            priceMinCents: 1000,
            withImageOnly: true,
            page: 4,
        );

        $geaendert = $original->with(types: [ListingType::Tausch], page: 1);

        self::assertSame('hypo', $geaendert->query);
        self::assertSame(7, $geaendert->speciesId);
        self::assertSame([Sex::Weiblich], $geaendert->sexes);
        self::assertSame(1000, $geaendert->priceMinCents);
        self::assertTrue($geaendert->withImageOnly);
        self::assertSame([ListingType::Tausch], $geaendert->types);
        self::assertSame(1, $geaendert->page);

        self::assertSame(4, $original->page, 'Das Original bleibt unveraendert.');
        self::assertSame([], $original->types);
    }

    public function testSortierwechselSpringtAufSeiteEins(): void
    {
        $geaendert = (new SearchCriteria(page: 9))->withSort(SortOrder::PreisAufsteigend);

        self::assertSame(1, $geaendert->page);
        self::assertSame(SortOrder::PreisAufsteigend, $geaendert->sort);
    }

    public function testMerkmalsfilterOhneZygositaetTrifftJede(): void
    {
        self::assertTrue((new MorphFilter(1))->matchesAnyZygosity());
        self::assertSame([], (new MorphFilter(1))->zygosityValues());

        $mit = new MorphFilter(1, [\Reptilienmarkt\Domain\Listing\Zygosity::Het]);
        self::assertFalse($mit->matchesAnyZygosity());
        self::assertSame(['het'], $mit->zygosityValues());
    }

    public function testUmkreisLiefertRechteckUndBeschriftung(): void
    {
        $radius = new RadiusFilter(new Coordinates(48.13743, 11.57549), SearchRadius::Km50, '80331', null, 'München');

        self::assertSame(50.0, $radius->kilometers());
        self::assertStringContainsString('80331 München', $radius->label());
        self::assertTrue($radius->boundingBox()->contains(new Coordinates(48.13743, 11.57549)));
    }
}
