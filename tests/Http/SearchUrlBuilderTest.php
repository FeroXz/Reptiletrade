<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Geo\Coordinates;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Listing\Zygosity;
use Reptilienmarkt\Domain\Search\MorphFilter;
use Reptilienmarkt\Domain\Search\RadiusFilter;
use Reptilienmarkt\Domain\Search\SearchCriteria;
use Reptilienmarkt\Domain\Search\SearchRadius;
use Reptilienmarkt\Domain\Search\SortOrder;
use Reptilienmarkt\Domain\Species\BnatschgStatus;
use Reptilienmarkt\Domain\Species\Species;
use Reptilienmarkt\Http\Search\SearchUrlBuilder;
use Reptilienmarkt\Http\Search\SearchUrlContext;
use Reptilienmarkt\Support\Slugger;

#[CoversClass(SearchUrlBuilder::class)]
#[CoversClass(SearchUrlContext::class)]
#[CoversClass(Slugger::class)]
final class SearchUrlBuilderTest extends TestCase
{
    private function bartagame(): Species
    {
        return new Species(
            1,
            'Pogona vitticeps',
            'Bartagame',
            'pogona-vitticeps',
            null,
            null,
            null,
            null,
            BnatschgStatus::NichtGeschuetzt,
            false,
            false,
            false,
            null,
            null,
            null,
            null,
            null,
            'bartagame',
        );
    }

    private function context(): SearchUrlContext
    {
        return new SearchUrlContext(
            $this->bartagame(),
            [7 => 'red', 8 => 'hypo', 9 => 'translucent'],
            'bayern',
        );
    }

    public function testOhneFilterNurDerGrundpfad(): void
    {
        self::assertSame('/markt/', SearchUrlBuilder::build(new SearchCriteria(), new SearchUrlContext()));
    }

    public function testArtWirdZumPfadsegment(): void
    {
        $url = SearchUrlBuilder::build(new SearchCriteria(speciesId: 1), $this->context());

        self::assertSame('/markt/bartagame/', $url);
    }

    /**
     * Der Beispielpfad aus der Aufgabenstellung.
     */
    public function testArtMorphsUndRegionErgebenDenSeoPfad(): void
    {
        $criteria = new SearchCriteria(
            speciesId: 1,
            morphs: [new MorphFilter(7), new MorphFilter(8), new MorphFilter(9)],
            admin1: 'Bayern',
        );

        self::assertSame('/markt/bartagame/red-hypo-translucent/bayern/', SearchUrlBuilder::build($criteria, $this->context()));
    }

    public function testUnbekannteArtLandetInDerQuery(): void
    {
        $url = SearchUrlBuilder::build(new SearchCriteria(speciesId: 99), $this->context());

        self::assertSame('/markt/?art_id=99', $url);
    }

    /**
     * Ein Pfad darf nur entstehen, wenn er die Suche verlustfrei abbildet.
     */
    public function testZygositaetVerhindertDenPfadUndWirdZurQuery(): void
    {
        $criteria = new SearchCriteria(
            speciesId: 1,
            morphs: [new MorphFilter(8, [Zygosity::Het])],
        );

        $url = SearchUrlBuilder::build($criteria, $this->context());

        self::assertSame('/markt/bartagame/?morph=8%3Ahet', $url);
    }

    public function testUnbekanntesMerkmalVerhindertDenPfad(): void
    {
        $criteria = new SearchCriteria(speciesId: 1, morphs: [new MorphFilter(999)]);

        self::assertSame('/markt/bartagame/?morph=999', SearchUrlBuilder::build($criteria, $this->context()));
    }

    public function testRegionOhneMerkmaleBleibtQueryParameter(): void
    {
        $criteria = new SearchCriteria(speciesId: 1, admin1: 'Bayern');

        self::assertSame('/markt/bartagame/?region=Bayern', SearchUrlBuilder::build($criteria, $this->context()));
    }

    public function testUebrigeFilterWerdenAngehaengt(): void
    {
        $criteria = new SearchCriteria(
            query: 'hypo',
            types: [ListingType::Verkauf],
            sexes: [Sex::Weiblich],
            priceMinCents: 5000,
            priceMaxCents: 150000,
            withImageOnly: true,
            sort: SortOrder::PreisAufsteigend,
            page: 3,
        );

        $url = SearchUrlBuilder::build($criteria, new SearchUrlContext());

        self::assertStringStartsWith('/markt/?', $url);
        foreach ([
            'q=hypo',
            'typ=verkauf',
            'geschlecht=w',
            'preis_min=50',
            'preis_max=1500',
            'mit_bild=1',
            'sortierung=preis_auf',
            'seite=3',
        ] as $teil) {
            self::assertStringContainsString($teil, $url);
        }
    }

    public function testMehrfachauswahlErzeugtMehrereParameter(): void
    {
        $criteria = new SearchCriteria(sexes: [Sex::Weiblich, Sex::Maennlich]);

        $url = SearchUrlBuilder::build($criteria, new SearchUrlContext());

        self::assertStringContainsString('geschlecht=w', $url);
        self::assertStringContainsString('geschlecht=m', $url);
    }

    public function testUmkreisWirdMitPlzUndRadiusGeschrieben(): void
    {
        $criteria = new SearchCriteria(
            radius: new RadiusFilter(new Coordinates(48.13743, 11.57549), SearchRadius::Km50, '80331', Country::De),
        );

        $url = SearchUrlBuilder::build($criteria, new SearchUrlContext());

        self::assertStringContainsString('plz=80331', $url);
        self::assertStringContainsString('umkreis=50', $url);
    }

    public function testStandardsortierungUndSeiteEinsStehenNichtInDerUrl(): void
    {
        $url = SearchUrlBuilder::build(new SearchCriteria(sort: SortOrder::Neueste, page: 1), new SearchUrlContext());

        self::assertSame('/markt/', $url);
    }

    public function testSonderzeichenWerdenKodiert(): void
    {
        $url = SearchUrlBuilder::build(new SearchCriteria(query: 'grüner baumpython & co'), new SearchUrlContext());

        self::assertStringNotContainsString(' ', $url);
        self::assertStringNotContainsString('&co', $url);
        self::assertStringContainsString('q=gr%C3%BCner%20baumpython%20%26%20co', $url);
    }

    public function testSluggerSchreibtUmlauteAus(): void
    {
        self::assertSame('griechische-landschildkroete', Slugger::slug('Griechische Landschildkröte'));
        self::assertSame('koenigspython', Slugger::slug('Königspython'));
        self::assertSame('red-hypo-translucent', Slugger::combine(['Red', 'Hypo', 'Translucent']));
        self::assertSame('', Slugger::slug('---'));
    }
}
