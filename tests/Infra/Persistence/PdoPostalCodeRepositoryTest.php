<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use Reptilienmarkt\Domain\Geo\Coordinates;
use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Geo\PostalCode;
use Reptilienmarkt\Domain\Geo\PostalCodeSource;
use Reptilienmarkt\Infra\Persistence\PdoPostalCodeRepository;
use Reptilienmarkt\Tests\DatabaseTestCase;

#[CoversClass(PdoPostalCodeRepository::class)]
final class PdoPostalCodeRepositoryTest extends DatabaseTestCase
{
    private PdoPostalCodeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new PdoPostalCodeRepository($this->database);
        $this->repository->upsertMany([
            new PostalCode(Country::De, '80331', 'München', 'Bayern', new Coordinates(48.13743, 11.57549)),
            new PostalCode(Country::De, '85221', 'Dachau', 'Bayern', new Coordinates(48.26043, 11.43406)),
            new PostalCode(Country::De, '82256', 'Fürstenfeldbruck', 'Bayern', new Coordinates(48.17796, 11.25469)),
            new PostalCode(Country::De, '86150', 'Augsburg', 'Bayern', new Coordinates(48.36615, 10.89779)),
            // Landshut liegt im Rechteck, aber ausserhalb des 50-km-Radius —
            // der Testfall fuer die zweite Stufe der Umkreissuche.
            new PostalCode(Country::De, '84028', 'Landshut', 'Bayern', new Coordinates(48.53718, 12.15194)),
            new PostalCode(Country::De, '10115', 'Berlin', 'Berlin', new Coordinates(52.52437, 13.41053)),
            new PostalCode(Country::At, '6020', 'Innsbruck', 'Tirol', new Coordinates(47.26266, 11.39454)),
        ]);
    }

    public function testFindetPostleitzahl(): void
    {
        $postalCode = $this->repository->find(Country::De, '80331');

        self::assertNotNull($postalCode);
        self::assertSame('München', $postalCode->placeName);
        self::assertSame('Bayern', $postalCode->admin1);
        self::assertEqualsWithDelta(48.13743, $postalCode->coordinates->latitude, 0.00001);
    }

    public function testFindetNichtsBeiFremdemLand(): void
    {
        self::assertNull($this->repository->find(Country::Ch, '80331'));
    }

    /**
     * Vorfilter ueber das Rechteck, danach exakte Distanz — genau so laeuft die
     * Umkreissuche in Phase 3.
     */
    public function testUmkreissucheLiefertNachEntfernungSortierteTreffer(): void
    {
        $center = new Coordinates(48.13743, 11.57549);
        $radiusKm = 50.0;

        $candidates = $this->repository->withinBoundingBox($center->boundingBox($radiusKm), Country::De);

        $withinRadius = [];
        foreach ($candidates as $candidate) {
            $distance = $center->distanceKmTo($candidate->coordinates);
            if ($distance <= $radiusKm) {
                $withinRadius[] = [$candidate->postalCode, $distance];
            }
        }

        usort($withinRadius, static fn(array $a, array $b): int => $a[1] <=> $b[1]);

        self::assertSame(
            ['80331', '85221', '82256'],
            array_column($withinRadius, 0),
            'Erwartet werden München, Dachau und Fürstenfeldbruck in dieser Reihenfolge.',
        );

        $candidateCodes = array_map(
            static fn(PostalCode $candidate): string => $candidate->postalCode,
            $candidates,
        );

        // Stufe 1: Berlin liegt weit ausserhalb und taucht im Vorfilter gar nicht auf.
        self::assertNotContains('10115', $candidateCodes);

        // Stufe 2: Landshut passiert das Rechteck, faellt aber an der exakten Distanz raus.
        self::assertContains('84028', $candidateCodes);
        self::assertNotContains('84028', array_column($withinRadius, 0));
    }

    public function testUmkreissucheKannLandUebergreifendSuchen(): void
    {
        $center = new Coordinates(47.26266, 11.39454);
        $candidates = $this->repository->withinBoundingBox($center->boundingBox(200.0));

        $countries = array_unique(array_map(
            static fn(PostalCode $candidate): string => $candidate->country->value,
            $candidates,
        ));

        sort($countries);
        self::assertSame(['AT', 'DE'], $countries);
    }

    public function testSucheNachPlzPraefix(): void
    {
        $results = $this->repository->search('80', Country::De);

        self::assertCount(1, $results);
        self::assertSame('80331', $results[0]->postalCode);
    }

    public function testSucheNachOrtsname(): void
    {
        $results = $this->repository->search('Mün');

        self::assertCount(1, $results);
        self::assertSame('München', $results[0]->placeName);
    }

    public function testZaehltProLand(): void
    {
        self::assertSame(7, $this->repository->count());
        self::assertSame(6, $this->repository->count(Country::De));
        self::assertSame(1, $this->repository->count(Country::At));
        self::assertSame(0, $this->repository->count(Country::Ch));
    }

    public function testErneuterImportAktualisiertStattZuDuplizieren(): void
    {
        $this->repository->upsertMany([
            new PostalCode(Country::De, '80331', 'München', 'Bayern', new Coordinates(48.1, 11.5), PostalCodeSource::Geonames),
        ]);

        self::assertSame(6, $this->repository->count(Country::De));

        $postalCode = $this->repository->find(Country::De, '80331');
        self::assertNotNull($postalCode);
        self::assertSame(PostalCodeSource::Geonames, $postalCode->source);
        self::assertEqualsWithDelta(48.1, $postalCode->coordinates->latitude, 0.00001);
    }
}
