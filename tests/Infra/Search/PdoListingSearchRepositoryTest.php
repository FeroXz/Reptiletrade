<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Geo\Coordinates;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Listing\CbStatus;
use Reptilienmarkt\Domain\Listing\Handover;
use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Domain\Listing\Sex;
use Reptilienmarkt\Domain\Listing\Zygosity;
use Reptilienmarkt\Domain\Search\FacetCounts;
use Reptilienmarkt\Domain\Search\MorphFilter;
use Reptilienmarkt\Domain\Search\RadiusFilter;
use Reptilienmarkt\Domain\Search\SearchCriteria;
use Reptilienmarkt\Domain\Search\SearchRadius;
use Reptilienmarkt\Domain\Search\SortOrder;
use Reptilienmarkt\Infra\Search\ListingQuery;
use Reptilienmarkt\Infra\Search\PdoListingSearchRepository;

#[CoversClass(PdoListingSearchRepository::class)]
#[CoversClass(ListingQuery::class)]
final class PdoListingSearchRepositoryTest extends SearchTestCase
{
    private int $bartagame;

    private int $koenigspython;

    private int $morphHypo;

    private int $morphTrans;

    private int $userId;

    /** Koordinaten echter Orte, damit die Distanzen nachrechenbar bleiben. */
    private const array MUENCHEN = [48.13743, 11.57549];

    private const array DACHAU = [48.26043, 11.43406];      // rund 17 km

    private const array AUGSBURG = [48.36615, 10.89779];    // rund 56 km

    private const array LANDSHUT = [48.53718, 12.15194];    // rund 62 km, liegt aber im Rechteck

    private const array BERLIN = [52.52437, 13.41053];      // rund 504 km

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser();
        $this->bartagame = $this->createSpeciesNamed('Pogona vitticeps', 'Bartagame');
        $this->koenigspython = $this->createSpeciesNamed('Python regius', 'Königspython');

        $this->morphHypo = $this->createMorph($this->bartagame, 'Hypomelanistic', ['Hypo']);
        $this->morphTrans = $this->createMorph($this->bartagame, 'Translucent', ['Trans']);

        $this->createPostalCode('DE', '80331', 'München', 'Bayern', self::MUENCHEN[0], self::MUENCHEN[1]);
        $this->createPostalCode('DE', '85221', 'Dachau', 'Bayern', self::DACHAU[0], self::DACHAU[1]);
        $this->createPostalCode('DE', '86150', 'Augsburg', 'Bayern', self::AUGSBURG[0], self::AUGSBURG[1]);
        $this->createPostalCode('DE', '10115', 'Berlin', 'Berlin', self::BERLIN[0], self::BERLIN[1]);
    }

    private function radius(SearchRadius $radius): RadiusFilter
    {
        return new RadiusFilter(
            new Coordinates(self::MUENCHEN[0], self::MUENCHEN[1]),
            $radius,
            '80331',
            Country::De,
            'München',
        );
    }

    public function testFindetNurOeffentlichSichtbareAnzeigen(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'Aktiv', 'aktiv');
        $this->createSearchableListing($this->userId, $this->bartagame, 'Reserviert', 'reserviert');
        $this->createSearchableListing($this->userId, $this->bartagame, 'Entwurf', 'entwurf');
        $this->createSearchableListing($this->userId, $this->bartagame, 'Verkauft', 'verkauft');
        $this->createSearchableListing($this->userId, $this->bartagame, 'Gesperrt', 'gesperrt');

        $result = $this->repository->search(new SearchCriteria());

        self::assertSame(2, $result->total);
        self::assertEqualsCanonicalizing(['Aktiv', 'Reserviert'], $this->titles($result));
    }

    public function testFiltertNachArt(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'Bartagame');
        $this->createSearchableListing($this->userId, $this->koenigspython, 'Python');

        $result = $this->repository->search(new SearchCriteria(speciesId: $this->bartagame));

        self::assertSame(['Bartagame'], $this->titles($result));
    }

    /**
     * Mehrere Merkmale werden UND-verknuepft.
     */
    public function testMehrereMerkmaleWerdenUndVerknuepft(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'Nur Hypo', morphs: [$this->morphHypo => 'visual']);
        $this->createSearchableListing($this->userId, $this->bartagame, 'Nur Trans', morphs: [$this->morphTrans => 'visual']);
        $this->createSearchableListing($this->userId, $this->bartagame, 'Beides', morphs: [
            $this->morphHypo => 'visual',
            $this->morphTrans => 'visual',
        ]);

        $result = $this->repository->search(new SearchCriteria(morphs: [
            new MorphFilter($this->morphHypo),
            new MorphFilter($this->morphTrans),
        ]));

        self::assertSame(['Beides'], $this->titles($result));
    }

    public function testMerkmalMitZygositaet(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'Sichtbar', morphs: [$this->morphHypo => 'visual']);
        $this->createSearchableListing($this->userId, $this->bartagame, 'Het', morphs: [$this->morphHypo => 'het']);
        $this->createSearchableListing($this->userId, $this->bartagame, 'Poss het', morphs: [$this->morphHypo => 'poss_het_66']);

        $visual = $this->repository->search(new SearchCriteria(morphs: [
            new MorphFilter($this->morphHypo, [Zygosity::Visual]),
        ]));
        self::assertSame(['Sichtbar'], $this->titles($visual));

        $beides = $this->repository->search(new SearchCriteria(morphs: [
            new MorphFilter($this->morphHypo, [Zygosity::Visual, Zygosity::Het]),
        ]));
        self::assertEqualsCanonicalizing(['Sichtbar', 'Het'], $this->titles($beides));

        $alle = $this->repository->search(new SearchCriteria(morphs: [new MorphFilter($this->morphHypo)]));
        self::assertCount(3, $alle->listings);
    }

    /**
     * Akzeptanzkriterium: Umkreissuche 50 km liefert korrekte, nach Entfernung
     * sortierte Ergebnisse.
     */
    public function testUmkreissucheFuenfzigKilometerSortiertNachEntfernung(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'München', postalCode: '80331', country: 'DE', lat: self::MUENCHEN[0], lng: self::MUENCHEN[1]);
        $this->createSearchableListing($this->userId, $this->bartagame, 'Dachau', postalCode: '85221', country: 'DE', lat: self::DACHAU[0], lng: self::DACHAU[1]);
        $this->createSearchableListing($this->userId, $this->bartagame, 'Augsburg', postalCode: '86150', country: 'DE', lat: self::AUGSBURG[0], lng: self::AUGSBURG[1]);
        $this->createSearchableListing($this->userId, $this->bartagame, 'Berlin', postalCode: '10115', country: 'DE', lat: self::BERLIN[0], lng: self::BERLIN[1]);

        $result = $this->repository->search(new SearchCriteria(
            radius: $this->radius(SearchRadius::Km50),
            sort: SortOrder::Entfernung,
        ));

        self::assertSame(['München', 'Dachau'], $this->titles($result));
        self::assertSame(2, $result->total);

        self::assertEqualsWithDelta(0.0, $result->listings[0]->distanceKm ?? -1, 0.1);
        self::assertEqualsWithDelta(17.2, $result->listings[1]->distanceKm ?? -1, 1.0);
    }

    /**
     * Regressionstest: PDO bindet Parameter als Text, und SQLite sortiert Text
     * ueber jede Zahl. Ohne CAST war der Radiusvergleich immer wahr — die
     * Umkreissuche lieferte dann das gesamte Rechteck statt des Kreises.
     */
    public function testUmkreisSchliesstDieEckenDesRechtecksAus(): void
    {
        // Landshut liegt im Rechteck um München, aber ausserhalb der 50-km-Scheibe.
        $this->createPostalCode('DE', '84028', 'Landshut', 'Bayern', self::LANDSHUT[0], self::LANDSHUT[1]);
        $this->createSearchableListing($this->userId, $this->bartagame, 'Landshut', postalCode: '84028', country: 'DE', lat: self::LANDSHUT[0], lng: self::LANDSHUT[1]);

        $box = $this->radius(SearchRadius::Km50)->boundingBox();
        $landshut = new Coordinates(self::LANDSHUT[0], self::LANDSHUT[1]);

        self::assertTrue($box->contains($landshut), 'Landshut muss im Rechteck liegen, sonst prueft der Test nichts.');
        self::assertGreaterThan(50.0, (new Coordinates(self::MUENCHEN[0], self::MUENCHEN[1]))->distanceKmTo($landshut));

        $result = $this->repository->search(new SearchCriteria(radius: $this->radius(SearchRadius::Km50)));

        self::assertSame(0, $result->total, 'Der Rechteck-Vorfilter darf nicht das Endergebnis sein.');
    }

    public function testGroessererUmkreisFindetMehr(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'Augsburg', postalCode: '86150', country: 'DE', lat: self::AUGSBURG[0], lng: self::AUGSBURG[1]);

        self::assertSame(0, $this->repository->search(new SearchCriteria(radius: $this->radius(SearchRadius::Km25)))->total);
        self::assertSame(1, $this->repository->search(new SearchCriteria(radius: $this->radius(SearchRadius::Km100)))->total);
    }

    /**
     * Regressionstest zur Typaffinitaet: Das unaere Plus vor price_cents nimmt
     * der Spalte ihre Affinitaet. Ohne CAST war der Preisfilter wirkungslos.
     */
    public function testPreisspanneGreiftTatsaechlich(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'Guenstig', priceCents: 5000);
        $this->createSearchableListing($this->userId, $this->bartagame, 'Mittel', priceCents: 50000);
        $this->createSearchableListing($this->userId, $this->bartagame, 'Teuer', priceCents: 900000);
        $this->createSearchableListing($this->userId, $this->bartagame, 'Ohne Preis', priceCents: null);

        $result = $this->repository->search(new SearchCriteria(priceMinCents: 10000, priceMaxCents: 100000));

        self::assertSame(['Mittel'], $this->titles($result));
    }

    public function testPreisobergrenzeSchliesstAnzeigenOhnePreisAus(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'Mit Preis', priceCents: 5000);
        $this->createSearchableListing($this->userId, $this->bartagame, 'Tausch', type: 'tausch', priceCents: null);

        $result = $this->repository->search(new SearchCriteria(priceMaxCents: 1000000));

        self::assertSame(['Mit Preis'], $this->titles($result));
    }

    public function testAltersfilterUeberSchlupfdatum(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'Jungtier', hatchDate: '2026-06-01');
        $this->createSearchableListing($this->userId, $this->bartagame, 'Halbwuechsig', hatchDate: '2025-08-01');
        $this->createSearchableListing($this->userId, $this->bartagame, 'Adult', hatchDate: '2021-01-01');

        self::assertSame(['Jungtier'], $this->titles($this->repository->search(new SearchCriteria(ageMaxMonths: 6))));
        self::assertSame(['Adult'], $this->titles($this->repository->search(new SearchCriteria(ageMinMonths: 48))));
        self::assertSame(['Halbwuechsig'], $this->titles($this->repository->search(new SearchCriteria(ageMinMonths: 6, ageMaxMonths: 24))));
    }

    public function testNurMitBild(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'Mit Bild', withImage: true);
        $this->createSearchableListing($this->userId, $this->bartagame, 'Ohne Bild', withImage: false);

        $result = $this->repository->search(new SearchCriteria(withImageOnly: true));

        self::assertSame(['Mit Bild'], $this->titles($result));
        self::assertNotNull($result->listings[0]->imagePath);
    }

    public function testRegionsfilterUeberDiePlzTabelle(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'Bayern', postalCode: '80331', country: 'DE');
        $this->createSearchableListing($this->userId, $this->bartagame, 'Berlin', postalCode: '10115', country: 'DE');

        $result = $this->repository->search(new SearchCriteria(admin1: 'Bayern'));

        self::assertSame(['Bayern'], $this->titles($result));
    }

    public function testVolltextFindetTitelUndMorphAlias(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'Prachtexemplar', description: 'Ruhiges Tier', morphs: [$this->morphHypo => 'visual']);
        $this->createSearchableListing($this->userId, $this->koenigspython, 'Königspython Weibchen');

        self::assertSame(['Prachtexemplar'], $this->titles($this->repository->search(new SearchCriteria(query: 'Pracht'))));
        // "Hypo" ist der Alias von "Hypomelanistic" und steht mit im Index.
        self::assertSame(['Prachtexemplar'], $this->titles($this->repository->search(new SearchCriteria(query: 'Hypo'))));
        self::assertSame(['Königspython Weibchen'], $this->titles($this->repository->search(new SearchCriteria(query: 'python'))));
    }

    public function testVolltextIgnoriertSonderzeichen(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'Rote Bartagame');

        foreach (['"', '*', 'NOT', 'rote OR', 'rote*'] as $eingabe) {
            $result = $this->repository->search(new SearchCriteria(query: $eingabe));
            self::assertLessThanOrEqual(1, $result->total, \sprintf('Eingabe "%s" darf die Abfrage nicht sprengen.', $eingabe));
        }
    }

    public function testSortierungNachPreis(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'C', priceCents: 30000);
        $this->createSearchableListing($this->userId, $this->bartagame, 'A', priceCents: 10000);
        $this->createSearchableListing($this->userId, $this->bartagame, 'B', priceCents: 20000);
        $this->createSearchableListing($this->userId, $this->bartagame, 'Ohne', priceCents: null);

        self::assertSame(['A', 'B', 'C', 'Ohne'], $this->titles($this->repository->search(new SearchCriteria(sort: SortOrder::PreisAufsteigend))));
        self::assertSame(['C', 'B', 'A', 'Ohne'], $this->titles($this->repository->search(new SearchCriteria(sort: SortOrder::PreisAbsteigend))));
    }

    public function testHervorgehobeneAnzeigenStehenOben(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'Neu', bumpedAt: '2026-08-02T10:00:00Z');
        $this->createSearchableListing($this->userId, $this->bartagame, 'Boost', bumpedAt: '2026-01-01T10:00:00Z', featured: true);

        self::assertSame(['Boost', 'Neu'], $this->titles($this->repository->search(new SearchCriteria())));
    }

    public function testFacettenZaehlenOhneDieEigeneDimension(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'V1', type: 'verkauf');
        $this->createSearchableListing($this->userId, $this->bartagame, 'V2', type: 'verkauf');
        $this->createSearchableListing($this->userId, $this->bartagame, 'T1', type: 'tausch');

        $result = $this->repository->search(new SearchCriteria(types: [ListingType::Verkauf]));

        self::assertSame(2, $result->total);
        // Trotz gesetztem Typfilter bleibt "tausch" sichtbar — sonst koennte man
        // die Auswahl nicht mehr wechseln.
        self::assertSame(2, $result->facets->count(FacetCounts::TYPE, 'verkauf'));
        self::assertSame(1, $result->facets->count(FacetCounts::TYPE, 'tausch'));
    }

    public function testAndereFacettenBeruecksichtigenDenFilter(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'V weiblich', type: 'verkauf', sex: 'w');
        $this->createSearchableListing($this->userId, $this->bartagame, 'T maennlich', type: 'tausch', sex: 'm');

        $result = $this->repository->search(new SearchCriteria(types: [ListingType::Verkauf]));

        self::assertSame(1, $result->facets->count(FacetCounts::SEX, 'w'));
        self::assertSame(0, $result->facets->count(FacetCounts::SEX, 'm'));
    }

    public function testArtfacetteLiefertDeutscheNamen(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'A');
        $this->createSearchableListing($this->userId, $this->koenigspython, 'B');

        $result = $this->repository->search(new SearchCriteria());

        self::assertSame('Bartagame', $result->facets->label((string) $this->bartagame));
        self::assertSame('Königspython', $result->facets->label((string) $this->koenigspython));
    }

    public function testMerkmalsfacette(): void
    {
        $this->createSearchableListing($this->userId, $this->bartagame, 'A', morphs: [$this->morphHypo => 'visual']);
        $this->createSearchableListing($this->userId, $this->bartagame, 'B', morphs: [$this->morphHypo => 'visual']);
        $this->createSearchableListing($this->userId, $this->bartagame, 'C', morphs: [$this->morphHypo => 'het']);

        $facet = $this->repository->morphFacet(new SearchCriteria());

        self::assertCount(2, $facet);
        self::assertSame(['morph_id' => $this->morphHypo, 'name' => 'Hypomelanistic', 'zygosity' => 'visual', 'anzahl' => 2], $facet[0]);
    }

    public function testSeitenweiseAusgabe(): void
    {
        for ($i = 1; $i <= 7; ++$i) {
            $this->createSearchableListing($this->userId, $this->bartagame, 'A' . $i, bumpedAt: \sprintf('2026-08-%02dT10:00:00Z', $i));
        }

        $seite1 = $this->repository->search(new SearchCriteria(perPage: 3));
        $seite3 = $this->repository->search(new SearchCriteria(page: 3, perPage: 3));

        self::assertSame(7, $seite1->total);
        self::assertSame(3, $seite1->pageCount());
        self::assertCount(3, $seite1->listings);
        self::assertCount(1, $seite3->listings);
        self::assertSame([], array_intersect($this->ids($seite1), $this->ids($seite3)));
        self::assertTrue($seite1->hasNextPage());
        self::assertFalse($seite3->hasNextPage());
    }

    public function testKombinierteFilter(): void
    {
        $this->createSearchableListing(
            $this->userId,
            $this->bartagame,
            'Treffer',
            type: 'verkauf',
            priceCents: 25000,
            sex: 'w',
            cbStatus: 'nz',
            postalCode: '85221',
            country: 'DE',
            lat: self::DACHAU[0],
            lng: self::DACHAU[1],
            handover: 'abholung',
            morphs: [$this->morphHypo => 'visual'],
            withImage: true,
        );

        $this->createSearchableListing(
            $this->userId,
            $this->bartagame,
            'Falsches Geschlecht',
            sex: 'm',
            postalCode: '85221',
            country: 'DE',
            lat: self::DACHAU[0],
            lng: self::DACHAU[1],
            morphs: [$this->morphHypo => 'visual'],
            withImage: true,
        );

        $this->createSearchableListing(
            $this->userId,
            $this->bartagame,
            'Zu weit weg',
            sex: 'w',
            postalCode: '10115',
            country: 'DE',
            lat: self::BERLIN[0],
            lng: self::BERLIN[1],
            morphs: [$this->morphHypo => 'visual'],
            withImage: true,
        );

        $result = $this->repository->search(new SearchCriteria(
            speciesId: $this->bartagame,
            morphs: [new MorphFilter($this->morphHypo, [Zygosity::Visual])],
            sexes: [Sex::Weiblich],
            types: [ListingType::Verkauf],
            cbStatuses: [CbStatus::Nachzucht],
            countries: [Country::De],
            handovers: [Handover::Abholung],
            priceMinCents: 10000,
            priceMaxCents: 50000,
            withImageOnly: true,
            radius: $this->radius(SearchRadius::Km50),
        ));

        self::assertSame(['Treffer'], $this->titles($result));
    }

    /**
     * Ruecksicherung gegen den teuersten Fehler der Suche: Ohne den passenden
     * Teilindex materialisiert SQLite die gesamte Treffermenge vor dem LIMIT.
     */
    public function testTrefferseiteSortiertUeberDenIndexOhneTemporaerenBaum(): void
    {
        for ($i = 0; $i < 300; ++$i) {
            $this->createSearchableListing(
                $this->userId,
                $i % 3 === 0 ? $this->koenigspython : $this->bartagame,
                'A' . $i,
                bumpedAt: \sprintf('2026-07-%02dT10:00:00Z', ($i % 28) + 1),
            );
        }

        // Ohne Statistiken waehlt SQLite den Index nach Heuristik statt nach Groesse.
        $this->database->pdo()->exec('ANALYZE');

        $plan = implode(' ', $this->repository->explain(new SearchCriteria())['seite']);

        self::assertStringContainsString('idx_listings_rank', $plan);
        self::assertStringNotContainsString('TEMP B-TREE FOR ORDER BY', $plan);
    }
}
