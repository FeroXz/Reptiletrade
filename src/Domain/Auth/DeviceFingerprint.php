<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Auth;

/**
 * Was von Herkunft und Geraet einer Sitzung aufbewahrt wird — und was nicht.
 *
 * Der Zweck dieser Angaben ist Wiedererkennung: "Das war ich, gestern, am
 * Telefon" gegen "das war ich nicht". Dafuer braucht es keine vollstaendige
 * IP-Adresse und keine 400 Zeichen Browserkennung. Eine vollstaendige IP ist
 * ein Personenbezug mit Standortaussage, den 30 Tage lang aufzubewahren
 * niemandem nuetzt; die letzten Bits tragen zur Wiedererkennung nichts bei,
 * was die ersten nicht schon sagen.
 *
 * Gekuerzt wird beim **Schreiben**, nicht beim Anzeigen: Was nicht in der
 * Datenbank steht, kann auch nicht versehentlich woanders landen.
 */
final readonly class DeviceFingerprint
{
    /**
     * Mehr als das braucht keine Wiedererkennung, und lange Kennungen sind
     * ohnehin zu drei Vierteln Versionsballast.
     */
    public const int MAX_AGENT_LENGTH = 180;

    /**
     * Kuerzt die Adresse auf das Netz.
     *
     * IPv4 verliert das letzte Oktett, IPv6 den Interface-Identifier — die
     * unteren 64 Bit, in denen bei manchen Geraeten die MAC-Adresse steckt.
     */
    public static function shortenIp(?string $ip): ?string
    {
        if ($ip === null || trim($ip) === '') {
            return null;
        }

        $ip = trim($ip);

        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) !== false) {
            $teile = explode('.', $ip);
            $teile[3] = '0';

            return implode('.', $teile);
        }

        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) === false) {
            // Weder das eine noch das andere: Ein kaputter Wert wird nicht
            // "repariert", sondern verworfen.
            return null;
        }

        $binaer = inet_pton($ip);

        if ($binaer === false) {
            return null;
        }

        $genullt = inet_ntop(substr($binaer, 0, 8) . str_repeat("\0", 8));

        return $genullt === false ? null : $genullt;
    }

    public static function shortenAgent(?string $agent): ?string
    {
        if ($agent === null) {
            return null;
        }

        // Zeilenumbrueche haben in einer Kennung nichts zu suchen; sie kaemen
        // nur aus einem Versuch, die Ausgabe zu zerlegen.
        $sauber = trim(str_replace(["\r", "\n", "\0"], ' ', $agent));

        return $sauber === '' ? null : mb_substr($sauber, 0, self::MAX_AGENT_LENGTH);
    }

    /**
     * Eine grobe Geraetebezeichnung fuer die Sitzungsliste.
     *
     * Bewusst grob: Die Liste soll die Frage "kenne ich das?" beantworten, und
     * dafuer reicht "Firefox auf Android". Eine genauere Auswertung waere ein
     * Wiedererkennungsmerkmal mehr, ohne dass jemand etwas davon haette.
     */
    public static function label(?string $agent): string
    {
        if ($agent === null || trim($agent) === '') {
            return 'Unbekanntes Gerät';
        }

        $browser = match (true) {
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        $system = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $system === null ? $browser : $browser . ' auf ' . $system;
    }
}
