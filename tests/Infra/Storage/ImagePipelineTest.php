<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Infra\Storage\ImageException;
use Reptilienmarkt\Infra\Storage\ImagePipeline;
use Reptilienmarkt\Tests\Support\JpegWithGps;

/**
 * Akzeptanzkriterium: Hochgeladene Bilder enthalten keine EXIF-GPS-Daten.
 */
#[CoversClass(ImagePipeline::class)]
final class ImagePipelineTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/rm-bilder-' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->workDir)) {
            rmdir($this->workDir);
        }

        parent::tearDown();
    }

    private function pipeline(): ImagePipeline
    {
        return new ImagePipeline();
    }

    /**
     * @return array{string, string}
     */
    private function paths(string $name = 'ziel'): array
    {
        return [$this->workDir . '/' . $name . '.webp', $this->workDir . '/' . $name . '-thumb.webp'];
    }

    public function testDasQuellbildTraegtWirklichGpsDaten(): void
    {
        // Ohne diesen Nachweis waere der eigentliche Test wertlos.
        $source = JpegWithGps::create($this->workDir . '/quelle.jpg');

        $exif = @exif_read_data($source);

        self::assertIsArray($exif);
        self::assertArrayHasKey('GPSLatitude', $exif);
        self::assertArrayHasKey('GPSLongitude', $exif);
    }

    public function testGpsDatenUeberlebenDieVerarbeitungNicht(): void
    {
        $source = JpegWithGps::create($this->workDir . '/quelle.jpg');
        [$target, $thumb] = $this->paths();

        $this->pipeline()->process($source, $target, $thumb);

        foreach ([$target, $thumb] as $ergebnis) {
            $inhalt = (string) file_get_contents($ergebnis);

            // Weder ein EXIF-Block noch die Kennung selbst dürfen übrig sein.
            self::assertStringNotContainsString('Exif', $inhalt);
            self::assertStringNotContainsString('GPS', $inhalt);
            self::assertFalse(@exif_read_data($ergebnis), 'Im Ergebnis dürfen keine EXIF-Daten mehr stehen.');
        }
    }

    public function testErgebnisIstWebp(): void
    {
        $source = JpegWithGps::create($this->workDir . '/quelle.jpg');
        [$target, $thumb] = $this->paths();

        $ergebnis = $this->pipeline()->process($source, $target, $thumb);

        self::assertFileExists($target);
        self::assertFileExists($thumb);

        $info = getimagesize($target);
        self::assertIsArray($info);
        self::assertSame('image/webp', $info['mime']);
        self::assertGreaterThan(0, $ergebnis->byteSize);
    }

    public function testGrosseBilderWerdenVerkleinert(): void
    {
        $gross = imagecreatetruecolor(4000, 3000);
        self::assertNotFalse($gross);
        imagejpeg($gross, $this->workDir . '/gross.jpg', 85);
        imagedestroy($gross);

        [$target, $thumb] = $this->paths();
        $ergebnis = $this->pipeline()->process($this->workDir . '/gross.jpg', $target, $thumb);

        self::assertSame(ImagePipeline::MAX_EDGE, max($ergebnis->width, $ergebnis->height));

        $thumbInfo = getimagesize($thumb);
        self::assertIsArray($thumbInfo);
        self::assertLessThanOrEqual(ImagePipeline::THUMB_EDGE, max($thumbInfo[0], $thumbInfo[1]));
    }

    public function testKleineBilderWerdenNichtVergroessert(): void
    {
        $klein = imagecreatetruecolor(320, 240);
        self::assertNotFalse($klein);
        imagejpeg($klein, $this->workDir . '/klein.jpg', 85);
        imagedestroy($klein);

        [$target, $thumb] = $this->paths();
        $ergebnis = $this->pipeline()->process($this->workDir . '/klein.jpg', $target, $thumb);

        self::assertSame(320, $ergebnis->width);
        self::assertSame(240, $ergebnis->height);
    }

    public function testUmbenannteDateiWirdAbgelehnt(): void
    {
        // Der vom Browser gemeldete Typ zaehlt nicht — nur der Inhalt.
        file_put_contents($this->workDir . '/schadcode.jpg', "<?php echo 'kein Bild';");
        [$target, $thumb] = $this->paths();

        $this->expectException(ImageException::class);

        $this->pipeline()->process($this->workDir . '/schadcode.jpg', $target, $thumb);
    }

    public function testLeereDateiWirdAbgelehnt(): void
    {
        file_put_contents($this->workDir . '/leer.jpg', '');
        [$target, $thumb] = $this->paths();

        $this->expectException(ImageException::class);

        $this->pipeline()->process($this->workDir . '/leer.jpg', $target, $thumb);
    }

    public function testFehlendeDateiWirdAbgelehnt(): void
    {
        [$target, $thumb] = $this->paths();

        $this->expectException(ImageException::class);

        $this->pipeline()->process($this->workDir . '/gibt-es-nicht.jpg', $target, $thumb);
    }
}
