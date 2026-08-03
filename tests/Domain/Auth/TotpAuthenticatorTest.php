<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Domain\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reptilienmarkt\Domain\Auth\TotpAuthenticator;
use Reptilienmarkt\Tests\Support\FrozenClock;

#[CoversClass(TotpAuthenticator::class)]
final class TotpAuthenticatorTest extends TestCase
{
    /**
     * Testvektor aus RFC 6238, Anhang B: Geheimnis "12345678901234567890"
     * (ASCII), SHA-1. In Base32 ist das GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ.
     */
    private const string RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private function authenticator(string $time = '2026-08-03T12:00:00+00:00', int $tolerance = 1): TotpAuthenticator
    {
        return new TotpAuthenticator(new FrozenClock(new DateTimeImmutable($time)), $tolerance);
    }

    /**
     * Die Referenzwerte stammen aus RFC 6238, Anhang B (SHA-1, 8 Stellen —
     * hier auf die letzten sechs gekuerzt, weil wir sechsstellig arbeiten).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function rfcVektoren(): iterable
    {
        yield '1970-01-01 00:00:59' => ['@59', '287082'];
        yield '2005-03-18 01:58:29' => ['@1111111109', '081804'];
        yield '2005-03-18 01:58:31' => ['@1111111111', '050471'];
        yield '2009-02-13 23:31:30' => ['@1234567890', '005924'];
        yield '2033-05-18 03:33:20' => ['@2000000000', '279037'];
    }

    #[DataProvider('rfcVektoren')]
    public function testCodesStimmenMitDemRfcTestvektorUeberein(string $zeit, string $erwartet): void
    {
        self::assertSame($erwartet, $this->authenticator($zeit)->currentCode(self::RFC_SECRET));
    }

    public function testDerAktuelleCodeWirdAkzeptiert(): void
    {
        $totp = $this->authenticator();

        self::assertTrue($totp->verify(self::RFC_SECRET, $totp->currentCode(self::RFC_SECRET)));
    }

    public function testEinFalscherCodeWirdAbgelehnt(): void
    {
        self::assertFalse($this->authenticator()->verify(self::RFC_SECRET, '000000'));
    }

    public function testCodeMitFalscherLaengeWirdAbgelehnt(): void
    {
        $totp = $this->authenticator();

        self::assertFalse($totp->verify(self::RFC_SECRET, '12345'));
        self::assertFalse($totp->verify(self::RFC_SECRET, '1234567'));
        self::assertFalse($totp->verify(self::RFC_SECRET, ''));
    }

    /**
     * Ungenaue Uhren sind der Normalfall, deshalb ein Fenster Toleranz —
     * aber auch nicht mehr.
     */
    public function testDasVorherigeFensterWirdNochAkzeptiert(): void
    {
        $vorher = $this->authenticator('2026-08-03T12:00:00+00:00')->currentCode(self::RFC_SECRET);

        self::assertTrue($this->authenticator('2026-08-03T12:00:30+00:00')->verify(self::RFC_SECRET, $vorher));
    }

    public function testZweiFensterZurueckWerdenAbgelehnt(): void
    {
        $alt = $this->authenticator('2026-08-03T12:00:00+00:00')->currentCode(self::RFC_SECRET);

        self::assertFalse($this->authenticator('2026-08-03T12:01:10+00:00')->verify(self::RFC_SECRET, $alt));
    }

    public function testOhneToleranzGiltNurDasAktuelleFenster(): void
    {
        $vorher = $this->authenticator('2026-08-03T12:00:00+00:00', 0)->currentCode(self::RFC_SECRET);

        self::assertFalse($this->authenticator('2026-08-03T12:00:30+00:00', 0)->verify(self::RFC_SECRET, $vorher));
    }

    public function testLeerzeichenUndTrennerImCodeStoerenNicht(): void
    {
        $totp = $this->authenticator();
        $code = $totp->currentCode(self::RFC_SECRET);

        self::assertTrue($totp->verify(self::RFC_SECRET, substr($code, 0, 3) . ' ' . substr($code, 3)));
    }

    public function testErzeugteGeheimnisseSindBase32UndUnterschiedlich(): void
    {
        $totp = $this->authenticator();
        $erstes = $totp->generateSecret();
        $zweites = $totp->generateSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $erstes);
        self::assertNotSame($erstes, $zweites);
    }

    public function testEinErzeugtesGeheimnisFunktioniert(): void
    {
        $totp = $this->authenticator();
        $geheimnis = $totp->generateSecret();

        self::assertTrue($totp->verify($geheimnis, $totp->currentCode($geheimnis)));
    }

    public function testUngueltigesGeheimnisAkzeptiertKeinenCode(): void
    {
        $totp = $this->authenticator();

        // "1" und "8" gibt es im Base32-Alphabet nicht.
        self::assertFalse($totp->verify('1818181818181818', '000000'));
    }

    public function testProvisioningUri(): void
    {
        $uri = $this->authenticator()->provisioningUri(self::RFC_SECRET, 'zuechter@example.tld', 'Reptilienmarkt');

        self::assertStringStartsWith('otpauth://totp/Reptilienmarkt:zuechter%40example.tld?', $uri);
        self::assertStringContainsString('secret=' . self::RFC_SECRET, $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }

    public function testErsatzcodesSindEindeutig(): void
    {
        $codes = $this->authenticator()->generateRecoveryCodes(8);

        self::assertCount(8, $codes);
        self::assertCount(8, array_unique($codes));
    }
}
