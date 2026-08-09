<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Notification;

/**
 * Die Kanaele, in denen die Plattform ungefragt schreibt.
 *
 * Der Schluessel ist zugleich der Zweck der Mail (MailMessage::$purpose). Damit
 * braucht es keine zweite Zuordnungstabelle, die auseinanderlaufen kann: Wer
 * eine Mail mit dem Zweck "suche.treffer" einreiht, hat damit schon gesagt,
 * welche Einstellung darueber entscheidet.
 */
enum NotificationChannel: string
{
    case NachrichtNeu = 'nachricht.neu';
    case SucheTreffer = 'suche.treffer';
    case AnzeigeAblauf = 'anzeige.ablauf';
    case HandelBestaetigung = 'handel.bestaetigung';
    case SystemWichtig = 'system.wichtig';

    /**
     * Ein Zweck ohne eigenen Kanal ist Systempost.
     *
     * Bestaetigungslinks, Passwortmails, Kontaktformular: Sie tragen einen
     * Zweck wie "konto.verify", der keinem Kanal entspricht — und fallen damit
     * auf system.wichtig, also auf "geht immer raus". Das ist die richtige
     * Richtung: Ein neuer, versehentlich nicht zugeordneter Zweck wird
     * zugestellt statt stillschweigend verschluckt.
     */
    public static function forPurpose(string $purpose): self
    {
        return self::tryFrom($purpose) ?? self::SystemWichtig;
    }

    /**
     * Nicht abschaltbar.
     *
     * system.wichtig traegt Kontosperren, Sicherheitshinweise und Aenderungen
     * an Rechtstexten. Diese Mails sind keine Werbung, sondern der Weg, auf dem
     * die Plattform ihren Pflichten nachkommt: Wer nicht erfaehrt, dass sein
     * Konto gesperrt wurde oder dass sich die Nutzungsbedingungen geaendert
     * haben, kann darauf nicht reagieren. Ein Abschalter dafuer waere ein
     * Schalter gegen die eigene Rechtsposition — und gegen die des Nutzers.
     */
    public function isMandatory(): bool
    {
        return $this === self::SystemWichtig;
    }

    /**
     * Voreinstellung: an — ausser bei Suchtreffern.
     *
     * Wer eine Suche speichert, entscheidet sich dort bewusst fuer die
     * Benachrichtigung. Ungefragt taeglich Trefferlisten zu schicken, waere
     * das, was die Abmeldefunktion ueberhaupt noetig macht.
     */
    public function defaultEnabled(): bool
    {
        return $this !== self::SucheTreffer;
    }

    public function labelKey(): string
    {
        return 'benachrichtigung.kanal.' . $this->value;
    }

    public function descriptionKey(): string
    {
        return 'benachrichtigung.kanal.' . $this->value . '.beschreibung';
    }

    /**
     * Die Kanaele in der Reihenfolge, in der sie im Formular stehen.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(self::cases(), static fn(self $kanal): bool => !$kanal->isMandatory()));
    }
}
