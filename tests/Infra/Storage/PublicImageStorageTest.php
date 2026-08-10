<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Infra\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Infra\Storage\PublicImageStorage;
use Reptilienmarkt\Tests\Support\ThrowingStreamWrapper;

/**
 * Das srcset einer Anzeigenkachel.
 *
 * Der Kern: Es entsteht aus dem, was in der Zeile steht, und nicht aus dem, was
 * auf der Platte liegt. Bei 24 Treffern waeren das sonst 72 Dateisystemzugriffe
 * je Trefferliste — fuer eine Antwort, die sich nur beim Upload aendert.
 */
#[CoversClass(PublicImageStorage::class)]
final class PublicImageStorageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ThrowingStreamWrapper::register();
    }

    protected function tearDown(): void
    {
        ThrowingStreamWrapper::unregister();

        parent::tearDown();
    }

    /**
     * Die Ablage liegt hinter einem Doppelgaenger, der bei jedem Zugriff wirft:
     * Ein durchgelaufener Test beweist damit, dass keiner stattgefunden hat.
     */
    public function testDasSrcsetFasstDieAblageNichtAn(): void
    {
        $storage = new PublicImageStorage(ThrowingStreamWrapper::SCHEME . '://uploads');

        self::assertSame(
            '/uploads/anzeigen/7/bild-400.webp 400w, '
            . '/uploads/anzeigen/7/bild-800.webp 800w, '
            . '/uploads/anzeigen/7/bild.webp 1600w',
            $storage->srcset('anzeigen/7/bild.webp', '400,800,1600'),
        );
    }

    public function testEinBildOhneVariantenLiefertEinLeeresSrcset(): void
    {
        $storage = new PublicImageStorage(ThrowingStreamWrapper::SCHEME . '://uploads');

        // Ein Bestandsbild vor dem Lauf von bin/reimage.php. Leer heisst: Das
        // img faellt auf sein src zurueck, statt auf eine 404 zu zeigen.
        self::assertSame('', $storage->srcset('anzeigen/7/bild.webp', null));
        self::assertSame('', $storage->srcset('anzeigen/7/bild.webp', ''));
    }

    public function testNurTeilweiseErzeugteBreitenStehenAuchNurTeilweiseImSrcset(): void
    {
        $storage = new PublicImageStorage(ThrowingStreamWrapper::SCHEME . '://uploads');

        self::assertSame(
            '/uploads/anzeigen/7/bild-400.webp 400w, /uploads/anzeigen/7/bild.webp 1600w',
            $storage->srcset('anzeigen/7/bild.webp', '400,1600'),
        );
    }

    /**
     * Ein Wert, den WIDTHS nicht kennt, hat keinen Pfad, unter dem eine Datei
     * laege — er waere ein 404 in der Trefferliste.
     */
    public function testUnbekannteBreitenWerdenUebergangen(): void
    {
        $storage = new PublicImageStorage(ThrowingStreamWrapper::SCHEME . '://uploads');

        self::assertSame(
            '/uploads/anzeigen/7/bild-800.webp 800w',
            $storage->srcset('anzeigen/7/bild.webp', '640, 800, unsinn'),
        );
    }

    public function testDieBreitenlisteIstSortiertUndAufBekannteBeschraenkt(): void
    {
        self::assertSame('400,800,1600', PublicImageStorage::widthList([1600, 400, 800]));
        self::assertSame('400', PublicImageStorage::widthList([400, 123]));
        self::assertSame('', PublicImageStorage::widthList([]));
    }
}
