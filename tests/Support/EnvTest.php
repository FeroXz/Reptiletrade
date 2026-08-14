<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Support\Env;

/**
 * Die Rangfolge zwischen .env-Datei und Prozessumgebung.
 *
 * Solange die Datei gewann, war sie nicht zu uebergehen: Ein Aufruf wie
 * `DB_DATABASE=/tmp/probe.sqlite php bin/migrate.php up` lief still gegen die
 * Datenbank aus der .env. Sichtbar wurde das an den Tests, die bin-Skripte als
 * Unterprozess fahren — sie liefen nur, solange keine .env im Projekt lag, und
 * scheiterten auf jedem Rechner, der nach der README eingerichtet war.
 */
#[CoversClass(Env::class)]
final class EnvTest extends TestCase
{
    private string $datei;

    /** @var list<string> */
    private array $gesetzt = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->datei = sys_get_temp_dir() . '/reptilienmarkt-env-' . bin2hex(random_bytes(6));
        Env::reset();
    }

    protected function tearDown(): void
    {
        if (is_file($this->datei)) {
            unlink($this->datei);
        }

        foreach ($this->gesetzt as $schluessel) {
            putenv($schluessel);
        }

        Env::reset();

        parent::tearDown();
    }

    private function schreibe(string $inhalt): void
    {
        file_put_contents($this->datei, $inhalt);
    }

    private function setzeUmgebung(string $schluessel, string $wert): void
    {
        putenv($schluessel . '=' . $wert);
        $this->gesetzt[] = $schluessel;
    }

    public function testDieUmgebungGehtDerDateiVor(): void
    {
        $this->schreibe("DB_DATABASE=storage/db/reptilienmarkt.sqlite\n");
        $this->setzeUmgebung('DB_DATABASE', '/tmp/probe.sqlite');

        Env::load($this->datei);

        self::assertSame('/tmp/probe.sqlite', Env::get('DB_DATABASE'));
    }

    public function testOhneUmgebungGiltDieDatei(): void
    {
        $this->schreibe("APP_LOCALE=de-AT\n");

        Env::load($this->datei);

        self::assertSame('de-AT', Env::get('APP_LOCALE'));
    }

    public function testOhneBeidesGiltDerVorgabewert(): void
    {
        $this->schreibe("APP_LOCALE=de-AT\n");

        Env::load($this->datei);

        self::assertSame('Europe/Berlin', Env::string('RM_GIBT_ES_NICHT', 'Europe/Berlin'));
        self::assertNull(Env::get('RM_GIBT_ES_NICHT'));
    }

    public function testDieRangfolgeGiltAuchFuerZahlenUndSchalter(): void
    {
        $this->schreibe("LEGAL_REVIEW_MAX_AGE_MONTHS=12\nAPP_DEBUG=0\n");
        $this->setzeUmgebung('LEGAL_REVIEW_MAX_AGE_MONTHS', '3');
        $this->setzeUmgebung('APP_DEBUG', '1');

        Env::load($this->datei);

        self::assertSame(3, Env::int('LEGAL_REVIEW_MAX_AGE_MONTHS'));
        self::assertTrue(Env::bool('APP_DEBUG'));
    }

    public function testKommentareUndAnfuehrungszeichenBleibenWieBisher(): void
    {
        $this->schreibe("# ein Kommentar\nMAIL_FROM_NAME=\"Reptilienmarkt\"\nMAIL_FROM='noreply@example.tld'\n");

        Env::load($this->datei);

        self::assertSame('Reptilienmarkt', Env::get('MAIL_FROM_NAME'));
        self::assertSame('noreply@example.tld', Env::get('MAIL_FROM'));
    }
}
