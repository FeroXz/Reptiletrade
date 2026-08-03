<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Support\Log\JsonLogger;
use Reptilienmarkt\Support\Log\LogLevel;
use stdClass;

#[CoversClass(JsonLogger::class)]
#[CoversClass(LogLevel::class)]
final class JsonLoggerTest extends TestCase
{
    private string $datei;

    protected function setUp(): void
    {
        parent::setUp();

        $this->datei = sys_get_temp_dir() . '/reptilienmarkt-log-' . bin2hex(random_bytes(6)) . '/app.log';
    }

    protected function tearDown(): void
    {
        if (is_file($this->datei)) {
            unlink($this->datei);
        }

        if (is_dir(\dirname($this->datei))) {
            rmdir(\dirname($this->datei));
        }

        parent::tearDown();
    }

    public function testSchreibtEineZeileJeEreignis(): void
    {
        $logger = new JsonLogger($this->datei, LogLevel::Debug);

        $logger->info('job.completed', ['job_id' => 7]);
        $logger->warning('job.retry', ['job_id' => 8]);

        $zeilen = $this->zeilen();

        self::assertCount(2, $zeilen);
        self::assertSame('job.completed', $zeilen[0]['ereignis']);
        self::assertSame('info', $zeilen[0]['stufe']);
        self::assertSame(7, $zeilen[0]['job_id']);
        self::assertSame('warning', $zeilen[1]['stufe']);
    }

    public function testDieSchwelleFiltertBeimSchreiben(): void
    {
        $logger = new JsonLogger($this->datei, LogLevel::Warning);

        $logger->debug('zu.leise');
        $logger->info('auch.zu.leise');
        $logger->warning('laut.genug');
        $logger->error('sehr.laut');

        $zeilen = $this->zeilen();

        self::assertCount(2, $zeilen);
        self::assertSame('laut.genug', $zeilen[0]['ereignis']);
        self::assertSame('sehr.laut', $zeilen[1]['ereignis']);
    }

    public function testGeheimnisseStehenNichtImProtokoll(): void
    {
        $logger = new JsonLogger($this->datei, LogLevel::Debug);

        $logger->error('auth.failed', [
            'email' => 'nutzer@example.tld',
            'passwort' => 'geheim123',
            'token' => 'abcdef',
            'Authorization' => 'Bearer xyz',
            'stripe_secret_key' => 'sk_live_123',
        ]);

        $roh = (string) file_get_contents($this->datei);

        self::assertStringNotContainsString('geheim123', $roh);
        self::assertStringNotContainsString('abcdef', $roh);
        self::assertStringNotContainsString('Bearer xyz', $roh);
        self::assertStringNotContainsString('sk_live_123', $roh);
        // Die E-Mail bleibt: Ohne sie ist ein Anmeldefehler nicht nachvollziehbar.
        self::assertStringContainsString('nutzer@example.tld', $roh);
    }

    public function testGeschwaerztWirdNurBeiWortgleichemSchluessel(): void
    {
        $logger = new JsonLogger($this->datei, LogLevel::Debug);

        // "token" wird geschwaerzt, "zugangstoken" als Zaehlwert nicht — sonst
        // waeren Betriebskennzahlen unlesbar.
        $logger->info('retention.enforced', ['zugangstoken' => 12, 'token' => 'abcdef']);

        $zeile = $this->zeilen()[0];

        self::assertSame(12, $zeile['zugangstoken']);
        self::assertSame('[entfernt]', $zeile['token']);
    }

    public function testGeheimnisseAuchInVerschachteltenFeldern(): void
    {
        $logger = new JsonLogger($this->datei, LogLevel::Debug);

        $logger->info('webhook.received', ['payload' => ['secret' => 'whsec_123', 'typ' => 'invoice.paid']]);

        $roh = (string) file_get_contents($this->datei);

        self::assertStringNotContainsString('whsec_123', $roh);
        self::assertStringContainsString('invoice.paid', $roh);
    }

    public function testLangeWerteWerdenGekuerzt(): void
    {
        $logger = new JsonLogger($this->datei, LogLevel::Debug);

        // Ein einzelner Eintrag darf das Protokoll nicht sprengen.
        $logger->error('job.failed', ['fehler' => str_repeat('x', 5000)]);

        $zeile = $this->zeilen()[0];

        self::assertIsString($zeile['fehler']);
        self::assertLessThan(2100, \strlen($zeile['fehler']));
    }

    public function testEinObjektWirdZumTypnamenStattTiefSerialisiert(): void
    {
        $logger = new JsonLogger($this->datei, LogLevel::Debug);

        $logger->info('irgendwas', ['dings' => new stdClass()]);

        self::assertSame('stdClass', $this->zeilen()[0]['dings']);
    }

    public function testDasVerzeichnisWirdBeiBedarfAngelegt(): void
    {
        self::assertDirectoryDoesNotExist(\dirname($this->datei));

        (new JsonLogger($this->datei, LogLevel::Debug))->info('erster.eintrag');

        self::assertFileExists($this->datei);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function zeilen(): array
    {
        $inhalt = trim((string) file_get_contents($this->datei));
        $zeilen = [];

        foreach (explode("\n", $inhalt) as $zeile) {
            /** @var array<string, mixed> $dekodiert */
            $dekodiert = json_decode($zeile, true, 512, \JSON_THROW_ON_ERROR);
            $zeilen[] = $dekodiert;
        }

        return $zeilen;
    }
}
