<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Job;

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
     * Der Zeitplan. Der Schluessel ist der Auftragstyp, der Wert die Stunde
     * (UTC), zu der er laufen soll — oder null fuer "bei jedem Lauf".
     *
     * @var array<string, int|null>
     */
    private const array SCHEDULE = [
        // Stuendlich: Was schnell wirken soll.
        'listing.archive' => null,
        'billing.expire' => null,

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
        $stunde = (int) $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('G');
        $eingeplant = [];

        foreach (self::SCHEDULE as $type => $hour) {
            if ($hour !== null && $hour !== $stunde) {
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
     * @return array<string, int|null>
     */
    public function plan(): array
    {
        return self::SCHEDULE;
    }
}
