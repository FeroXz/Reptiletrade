<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Privacy;

use DateTimeImmutable;
use Reptilienmarkt\Support\Clock;

/**
 * Liest config/aufbewahrung.php.
 *
 * Eine Frist von 0 schaltet die jeweilige Loeschung ab — das ist eine
 * bewusste Entscheidung des Betreibers und kein Fehler. Ein negativer oder
 * unsinniger Wert dagegen schon.
 */
final readonly class RetentionPolicy
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config,
        private Clock $clock,
    ) {}

    public function days(string $key): int
    {
        $value = $this->config[$key] ?? null;

        if (!\is_int($value) || $value < 0) {
            throw new RetentionConfigurationException(
                \sprintf('In config/aufbewahrung.php fehlt "%s" oder es ist keine Zahl ab 0.', $key),
            );
        }

        return $value;
    }

    public function isEnabled(string $key): bool
    {
        return $this->days($key) > 0;
    }

    /**
     * Der Stichtag: Alles davor faellt unter die Frist.
     */
    public function cutoff(string $key): ?DateTimeImmutable
    {
        $days = $this->days($key);

        return $days === 0 ? null : $this->clock->now()->modify(\sprintf('-%d days', $days));
    }

    /**
     * Wie viele Fassungen je redaktionellem Eintrag aufgehoben werden.
     *
     * Eine Anzahl, keine Frist — und deshalb eine eigene Methode statt eines
     * weiteren Aufrufs von days(): Wer "30" hier als Tage laese, wuerfe die
     * Vorgeschichte eines Textes weg, an dem einen Monat lang niemand
     * gearbeitet hat.
     *
     * @throws RetentionConfigurationException
     */
    public function contentRevisions(): int
    {
        $value = $this->config['inhalt_fassungen_je_eintrag'] ?? null;

        if (!\is_int($value) || $value < 1) {
            throw new RetentionConfigurationException(
                'In config/aufbewahrung.php fehlt "inhalt_fassungen_je_eintrag" oder es ist keine Zahl ab 1. '
                . 'Eine 0 waere kein Abschalten, sondern der Verlust jeder Rueckkehrmoeglichkeit.',
            );
        }

        return $value;
    }

    /**
     * Tage vor dem Ablauf, an denen erinnert wird — absteigend sortiert.
     *
     * @return list<int>
     */
    public function reminderDays(): array
    {
        $value = $this->config['ablauf_erinnerung_tage'] ?? [];

        if (!\is_array($value)) {
            throw new RetentionConfigurationException('"ablauf_erinnerung_tage" muss eine Liste sein.');
        }

        $days = [];
        foreach ($value as $entry) {
            if (!\is_int($entry) || $entry < 1) {
                throw new RetentionConfigurationException('"ablauf_erinnerung_tage" darf nur Zahlen ab 1 enthalten.');
            }
            $days[] = $entry;
        }

        rsort($days);

        return $days;
    }

    /**
     * Alle Fristen fuer die Anzeige im Admin.
     *
     * @return array<string, int>
     */
    public function all(): array
    {
        $fristen = [];

        foreach ($this->config as $key => $value) {
            if (\is_string($key) && \is_int($value)) {
                $fristen[$key] = $value;
            }
        }

        return $fristen;
    }
}
