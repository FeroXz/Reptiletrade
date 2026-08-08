<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Job;

use DateTimeImmutable;
use DateTimeZone;
use Reptilienmarkt\Support\Clock;

/**
 * Plant die wiederkehrenden Aufgaben ein.
 *
 * Ein eigener Schritt statt Cron-Eintraegen je Aufgabe: So steht der Zeitplan
 * an einer Stelle im Code, laesst sich testen, und die Auftraege laufen alle
 * durch denselben Worker mit denselben Wiederholungsregeln.
 *
 * Doppelt eingeplant wird nichts: Steht ein Auftrag desselben Typs noch aus,
 * wird er nicht erneut angelegt. Ein haengender Job soll sich nicht zu einem
 * Stapel auswachsen.
 */
final readonly class JobScheduler
{
    /**
     * Der Zeitplan. Der Schluessel ist der Auftragstyp; der Wert ist entweder
     * ein wiederkehrender Abstand oder die Stunde (UTC), zu der der Auftrag
     * einmal taeglich laufen soll.
     *
     * @var array<string, JobInterval|int>
     */
    private const array SCHEDULE = [
        // Viertelstuendlich: Ein geplanter Beitrag soll nicht bis zu einer
        // Stunde zu spaet erscheinen. Dafuer muss die Crontab oefter aufrufen —
        // siehe docs/INSTALLATION.md.
        'content.publish' => JobInterval::Viertelstuendlich,

        // Stuendlich: Was schnell wirken soll.
        'listing.archive' => JobInterval::Stuendlich,
        'billing.expire' => JobInterval::Stuendlich,
        // Eine befristete Sperre, die niemand aufhebt, ist eine unbefristete.
        'user.ban_expiry' => JobInterval::Stuendlich,

        // Nachts, wenn wenig los ist.
        'listing.expiry_notice' => 6,
        'saved_search.alert' => 7,
        'retention.enforce' => 3,
        'media.cleanup' => 4,
        'log.rotate' => 4,
    ];

    public function __construct(
        private JobRepository $jobs,
        private Clock $clock,
    ) {}

    /**
     * Legt an, was jetzt faellig ist.
     *
     * @return list<string> die eingeplanten Typen
     */
    public function schedule(): array
    {
        $jetzt = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $stunde = (int) $jetzt->format('G');
        $eingeplant = [];

        foreach (self::SCHEDULE as $type => $takt) {
            if ($takt instanceof JobInterval) {
                if (!$this->intervalIsDue($type, $takt, $jetzt)) {
                    continue;
                }
            } elseif ($takt !== $stunde) {
                continue;
            }

            // Steht schon einer aus, reicht das.
            if ($this->jobs->hasPending($type)) {
                continue;
            }

            $payload = $type === 'saved_search.alert' ? ['frequenz' => 'taeglich'] : [];

            $this->jobs->enqueue($type, $payload);
            $eingeplant[] = $type;
        }

        return $eingeplant;
    }

    /**
     * Ist der Abstand seit dem letzten Auftrag dieses Typs verstrichen?
     *
     * Eine Minute Nachsicht, weil eine Crontab nie auf die Sekunde laeuft: Ohne
     * sie fiele bei einem Aufruf um 13:00:59 der naechste um 14:00:03 durch,
     * und die Aufgabe liefe faktisch nur alle zwei Stunden.
     */
    private function intervalIsDue(string $type, JobInterval $interval, DateTimeImmutable $now): bool
    {
        $letzter = $this->jobs->lastEnqueuedAt($type);

        if ($letzter === null) {
            return true;
        }

        $vergangen = ($now->getTimestamp() - $letzter->getTimestamp()) / 60;

        return $vergangen >= $interval->minutes() - 1;
    }

    /**
     * Plant einen Auftrag von Hand ein — fuer die Kommandozeile.
     *
     * @param array<string, mixed> $payload
     *
     * @throws JobException
     */
    public function enqueueOnce(string $type, array $payload = []): int
    {
        if (!\array_key_exists($type, self::SCHEDULE) && $type !== 'search.reindex') {
            throw new JobException(\sprintf('Unbekannter Auftragstyp "%s".', $type));
        }

        return $this->jobs->enqueue($type, $payload);
    }

    /**
     * @return array<string, JobInterval|int>
     */
    public function plan(): array
    {
        return self::SCHEDULE;
    }
}
