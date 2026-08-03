<?php

declare(strict_types=1);

namespace Reptilienmarkt\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Zeitstempel fuer die Datenbank — immer UTC, immer mit Z.
 *
 * Der Grund fuer diese Klasse: Die Uhr der Anwendung laeuft in Europe/Berlin,
 * die Datenbank speichert Text. Wer eine Berliner Zeit mit format('...\Z')
 * schreibt, haengt ein Z an eine Zeit, die keine UTC-Zeit ist. Beim Lesen wird
 * sie dann als UTC verstanden und liegt ein bis zwei Stunden in der Zukunft —
 * Sitzungen und Token leben so laenger als eingestellt, Sperren enden frueher.
 *
 * Deshalb geht jeder Schreibvorgang durch utc(): erst umrechnen, dann
 * formatieren.
 */
final class Timestamp
{
    public const string FORMAT = 'Y-m-d\TH:i:s\Z';

    public static function utc(DateTimeInterface $moment): string
    {
        return $moment instanceof DateTimeImmutable
            ? $moment->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT)
            : DateTimeImmutable::createFromInterface($moment)->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    public static function utcOrNull(?DateTimeInterface $moment): ?string
    {
        return $moment === null ? null : self::utc($moment);
    }

    /**
     * Der aktuelle Zeitpunkt in UTC. Nur fuer Spalten wie created_at, die
     * keinen fachlichen Bezug zur Uhr der Anwendung haben.
     */
    public static function now(): string
    {
        return gmdate(self::FORMAT);
    }

    /**
     * Liest einen gespeicherten Zeitstempel zurueck.
     */
    public static function parse(?string $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : new DateTimeImmutable($value);
    }
}
