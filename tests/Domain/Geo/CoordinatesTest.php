<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Geo;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Geo\BoundingBox;
use Reptilienmarkt\Domain\Geo\Coordinates;

#[CoversClass(Coordinates::class)]
#[CoversClass(BoundingBox::class)]
final class CoordinatesTest extends TestCase
{
    public function testDistanzBerlinMuenchen(): void
    {
        $berlin = new Coordinates(52.52437, 13.41053);
        $muenchen = new Coordinates(48.13743, 11.57549);

        // Luftlinie rund 504 km.
        self::assertEqualsWithDelta(504.0, $berlin->distanceKmTo($muenchen), 5.0);
    }

    public function testDistanzIstSymmetrisch(): void
    {
        $hamburg = new Coordinates(53.55073, 9.99302);
        $wien = new Coordinates(48.20849, 16.37208);

        self::assertEqualsWithDelta(
            $hamburg->distanceKmTo($wien),
            $wien->distanceKmTo($hamburg),
            0.0001,
        );
    }

    public function testDistanzZuSichSelbstIstNull(): void
    {
        $point = new Coordinates(51.05089, 13.73832);

        self::assertSame(0.0, $point->distanceKmTo($point));
    }

    public function testBoundingBoxUmschliesstDenRadius(): void
    {
        $center = new Coordinates(50.0, 10.0);
        $box = $center->boundingBox(50.0);

        self::assertTrue($box->contains($center));

        // Ein Punkt exakt 40 km noerdlich liegt im Rechteck ...
        $inside = new Coordinates(50.0 + 40.0 / 111.19, 10.0);
        self::assertTrue($box->contains($inside));
        self::assertLessThan(50.0, $center->distanceKmTo($inside));

        // ... ein Punkt 80 km noerdlich nicht mehr.
        $outside = new Coordinates(50.0 + 80.0 / 111.19, 10.0);
        self::assertFalse($box->contains($outside));
    }

    /**
     * Das Rechteck ist der Vorfilter: Es darf niemals einen Punkt innerhalb des
     * Radius verwerfen, auch wenn es umgekehrt zu viel durchlaesst.
     */
    public function testBoundingBoxVerwirftKeinenPunktImRadius(): void
    {
        $center = new Coordinates(48.13743, 11.57549);
        $radius = 50.0;
        $box = $center->boundingBox($radius);

        for ($bearing = 0; $bearing < 360; $bearing += 15) {
            $radians = deg2rad((float) $bearing);
            $latitude = $center->latitude + ($radius / 111.19) * cos($radians);
            $longitude = $center->longitude
                + ($radius / (111.19 * cos(deg2rad($center->latitude)))) * sin($radians);

            $point = new Coordinates($latitude, $longitude);
            if ($center->distanceKmTo($point) > $radius) {
                continue;
            }

            self::assertTrue(
                $box->contains($point),
                \sprintf('Punkt bei %d Grad liegt im Radius, aber nicht im Rechteck.', $bearing),
            );
        }
    }

    public function testUngueltigerBreitengradWirdAbgelehnt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Coordinates(91.0, 0.0);
    }

    public function testUngueltigerLaengengradWirdAbgelehnt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Coordinates(0.0, 181.0);
    }
}
