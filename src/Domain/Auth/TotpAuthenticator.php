<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Auth;

use Reptilienmarkt\Support\Clock;

/**
 * Zeitbasierte Einmalpasswoerter nach RFC 6238 (TOTP über HOTP, RFC 4226).
 *
 * Eigene Umsetzung statt Fremdpaket: Es sind dreissig Zeilen Kern, die
 * Schnittstelle ist seit 2011 unveraendert, und die Alternative waere eine
 * weitere Abhaengigkeit im Anmeldeweg — genau dort, wo man am wenigsten
 * fremden Code haben moechte.
 *
 * Der Toleranzbereich von einem Zeitfenster in jede Richtung faengt
 * ungenaue Uhren ab. Groesser sollte er nicht sein: Jedes zusaetzliche
 * Fenster verlaengert die Lebensdauer eines abgefangenen Codes.
 */
final readonly class TotpAuthenticator
{
    public const int PERIOD = 30;

    public const int DIGITS = 6;

    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        private Clock $clock,
        private int $tolerance = 1,
    ) {}

    /**
     * Erzeugt ein neues Geheimnis in Base32 — 160 Bit, wie von RFC 4226
     * empfohlen.
     */
    public function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * Prueft einen Code gegen das Geheimnis, inklusive Toleranzfenster.
     */
    public function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';

        if (\strlen($code) !== self::DIGITS) {
            return false;
        }

        // Ein Geheimnis, das sich nicht dekodieren laesst, darf keinen Code
        // akzeptieren. Ohne diese Pruefung liefe die Berechnung auf einen
        // festen Ersatzwert hinaus — und der waere erratbar.
        if (self::base32Decode($secret) === '') {
            return false;
        }

        $counter = intdiv($this->clock->now()->getTimestamp(), self::PERIOD);
        $valid = false;

        // Bewusst ohne vorzeitigen Abbruch: Alle Fenster werden geprueft, damit
        // die Laufzeit nicht verraet, welches Fenster gepasst hat.
        for ($offset = -$this->tolerance; $offset <= $this->tolerance; ++$offset) {
            if (hash_equals($this->codeAt($secret, $counter + $offset), $code)) {
                $valid = true;
            }
        }

        return $valid;
    }

    /**
     * Der aktuelle Code — fuer Tests und fuer die Anzeige beim Einrichten.
     */
    public function currentCode(string $secret): string
    {
        if (self::base32Decode($secret) === '') {
            throw new TotpException('Das Geheimnis ist kein gueltiges Base32.');
        }

        return $this->codeAt($secret, intdiv($this->clock->now()->getTimestamp(), self::PERIOD));
    }

    /**
     * Die otpauth-URI fuer den QR-Code der Authenticator-App.
     */
    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return \sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    /**
     * Ersatzcodes fuer den Fall, dass das Telefon weg ist. Sie werden wie
     * Passwoerter gehasht abgelegt — hier entstehen nur die Klartexte.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; ++$i) {
            $codes[] = \sprintf('%s-%s', bin2hex(random_bytes(2)), bin2hex(random_bytes(2)));
        }

        return $codes;
    }

    /**
     * Nur mit dekodierbarem Geheimnis aufzurufen — die Aufrufer pruefen das.
     */
    private function codeAt(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);

        // Dynamic Truncation nach RFC 4226, Abschnitt 5.4.
        $offset = \ord($hash[19]) & 0x0F;
        $binary = ((\ord($hash[$offset]) & 0x7F) << 24)
            | ((\ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((\ord($hash[$offset + 2]) & 0xFF) << 8)
            | (\ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % 10 ** self::DIGITS), self::DIGITS, '0', \STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $output .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0', \STR_PAD_RIGHT))];
        }

        return $output;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(str_replace([' ', '-', '='], '', $secret));
        $bits = '';

        foreach (str_split($secret) as $character) {
            $index = strpos(self::ALPHABET, $character);

            if ($index === false) {
                return '';
            }

            $bits .= str_pad(decbin($index), 5, '0', \STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (\strlen($chunk) === 8) {
                $bytes .= \chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
