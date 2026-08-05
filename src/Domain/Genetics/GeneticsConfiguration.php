<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Genetics;

use Reptilienmarkt\Domain\Setting\Settings;

/**
 * Liest config/genetik.php.
 *
 * Wie bei der Rechts-Engine und der Abrechnung gilt: Ein unbekannter Typ fuehrt
 * sofort zu einem Fehler. Eine stillschweigend auf null gefallene Gelegegroesse
 * ergaebe "0 erwartete Schluepflinge", eine verschluckte Superform eine falsche
 * Verteilung — beides faellt spaeter niemandem mehr auf.
 */
final readonly class GeneticsConfiguration
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config,
        private ?Settings $settings = null,
    ) {}

    /**
     * Der Hauptschalter. Das Setting sticht die Konfigurationsdatei.
     */
    public function enabled(): bool
    {
        $fromFile = $this->config['enabled'] ?? false;

        if (!\is_bool($fromFile)) {
            throw new GeneticsConfigurationException('"enabled" muss true oder false sein.');
        }

        $key = $this->config['setting_key'] ?? null;

        if ($this->settings === null || !\is_string($key) || $key === '') {
            return $fromFile;
        }

        return $this->settings->bool($key, $fromFile);
    }

    public function rolloutPercentage(): int
    {
        $value = $this->config['rollout_percentage'] ?? 100;

        if (!\is_int($value) || $value < 0 || $value > 100) {
            throw new GeneticsConfigurationException('"rollout_percentage" muss zwischen 0 und 100 liegen.');
        }

        return $value;
    }

    /**
     * Ist der Rechner fuer dieses Konto freigeschaltet?
     *
     * Die Zuordnung haengt an der Konto-ID, nicht am Zufall: Wer den Rechner
     * einmal gesehen hat, sieht ihn beim naechsten Aufruf wieder. Ein
     * Merkmal, das zwischen zwei Klicks verschwindet, erzeugt Meldungen statt
     * Erkenntnissen.
     */
    public function isEnabledFor(?int $userId): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $percentage = $this->rolloutPercentage();

        if ($percentage >= 100) {
            return true;
        }

        if ($userId === null || $percentage <= 0) {
            return false;
        }

        return crc32('genetik:' . $userId) % 100 < $percentage;
    }

    public function maxCombinations(): int
    {
        $value = $this->config['max_combinations'] ?? 20000;

        if (!\is_int($value) || $value < 1) {
            throw new GeneticsConfigurationException('"max_combinations" muss eine positive ganze Zahl sein.');
        }

        return $value;
    }

    public function sexSystem(string $speciesSlug): SexSystem
    {
        $value = $this->speciesValue($speciesSlug, 'geschlechtssystem');

        if (!\is_string($value)) {
            throw new GeneticsConfigurationException(\sprintf('"geschlechtssystem" von "%s" muss eine Zeichenkette sein.', $speciesSlug));
        }

        return SexSystem::tryFrom($value)
            ?? throw new GeneticsConfigurationException(\sprintf('Unbekanntes Geschlechtssystem "%s" bei "%s".', $value, $speciesSlug));
    }

    /**
     * Superform => Basismerkmal.
     *
     * @return array<string, string>
     */
    public function superForms(string $speciesSlug): array
    {
        $value = $this->speciesValue($speciesSlug, 'superformen');

        if (!\is_array($value)) {
            throw new GeneticsConfigurationException(\sprintf('"superformen" von "%s" muss eine Liste sein.', $speciesSlug));
        }

        $forms = [];
        foreach ($value as $super => $base) {
            if (!\is_string($super) || !\is_string($base)) {
                throw new GeneticsConfigurationException(\sprintf('"superformen" von "%s": Merkmalsnamen erwartet.', $speciesSlug));
            }

            $forms[$super] = $base;
        }

        return $forms;
    }

    /**
     * Merkmal => Tierschutzhinweis.
     *
     * @return array<string, string>
     */
    public function welfareNotes(string $speciesSlug): array
    {
        $value = $this->speciesValue($speciesSlug, 'tierschutz');

        if (!\is_array($value)) {
            throw new GeneticsConfigurationException(\sprintf('"tierschutz" von "%s" muss eine Liste sein.', $speciesSlug));
        }

        $notes = [];
        foreach ($value as $morph => $note) {
            if (!\is_string($morph) || !\is_string($note)) {
                throw new GeneticsConfigurationException(\sprintf('"tierschutz" von "%s": Merkmal und Hinweis erwartet.', $speciesSlug));
            }

            $notes[$morph] = $note;
        }

        return $notes;
    }

    /**
     * @return list<LethalCombo>
     */
    public function lethalCombos(string $speciesSlug): array
    {
        $value = $this->speciesValue($speciesSlug, 'letalkombinationen');

        if (!\is_array($value)) {
            throw new GeneticsConfigurationException(\sprintf('"letalkombinationen" von "%s" muss eine Liste sein.', $speciesSlug));
        }

        $combos = [];
        foreach ($value as $entry) {
            if (!\is_array($entry)) {
                throw new GeneticsConfigurationException(\sprintf('"letalkombinationen" von "%s": Eintraege muessen Listen sein.', $speciesSlug));
            }

            $morphs = $entry['merkmale'] ?? null;
            $rate = $entry['anteil'] ?? 1.0;
            $note = $entry['hinweis'] ?? '';

            if (!\is_array($morphs) || \count($morphs) < 2) {
                throw new GeneticsConfigurationException(
                    \sprintf('"letalkombinationen" von "%s": "merkmale" braucht mindestens zwei Namen.', $speciesSlug),
                );
            }

            $names = [];
            foreach ($morphs as $morph) {
                if (!\is_string($morph)) {
                    throw new GeneticsConfigurationException(\sprintf('"letalkombinationen" von "%s": Merkmalsnamen erwartet.', $speciesSlug));
                }

                $names[] = $morph;
            }

            if (!is_numeric($rate) || (float) $rate < 0.0 || (float) $rate > 1.0) {
                throw new GeneticsConfigurationException(\sprintf('"letalkombinationen" von "%s": "anteil" muss zwischen 0 und 1 liegen.', $speciesSlug));
            }

            if (!\is_string($note)) {
                throw new GeneticsConfigurationException(\sprintf('"letalkombinationen" von "%s": "hinweis" muss eine Zeichenkette sein.', $speciesSlug));
            }

            $combos[] = new LethalCombo($names, (float) $rate, $note);
        }

        return $combos;
    }

    public function clutch(string $speciesSlug): ClutchProfile
    {
        $value = $this->speciesValue($speciesSlug, 'gelege');

        if (!\is_array($value)) {
            throw new GeneticsConfigurationException(\sprintf('"gelege" von "%s" muss eine Liste sein.', $speciesSlug));
        }

        $size = $value['groesse'] ?? null;
        $rate = $value['schlupfquote'] ?? null;

        if (!\is_int($size) || $size < 1) {
            throw new GeneticsConfigurationException(\sprintf('"gelege.groesse" von "%s" muss eine positive ganze Zahl sein.', $speciesSlug));
        }

        if (!is_numeric($rate) || (float) $rate <= 0.0 || (float) $rate > 1.0) {
            throw new GeneticsConfigurationException(\sprintf('"gelege.schlupfquote" von "%s" muss zwischen 0 und 1 liegen.', $speciesSlug));
        }

        return new ClutchProfile($size, (float) $rate);
    }

    /**
     * Der Eintrag der Art, sonst die Voreinstellung. Ein fehlender Art-Eintrag
     * ist kein Fehler — der Artenstamm hat 58 Arten, Merkmalskataloge gibt es
     * nur fuer einen Teil davon.
     */
    private function speciesValue(string $speciesSlug, string $key): mixed
    {
        $species = $this->config['arten'] ?? [];
        $defaults = $this->config['standard'] ?? [];

        if (!\is_array($species) || !\is_array($defaults)) {
            throw new GeneticsConfigurationException('"arten" und "standard" muessen Listen sein.');
        }

        $entry = $species[$speciesSlug] ?? null;

        if ($entry !== null && !\is_array($entry)) {
            throw new GeneticsConfigurationException(\sprintf('Der Eintrag zu "%s" muss eine Liste sein.', $speciesSlug));
        }

        return $entry[$key] ?? $defaults[$key] ?? null;
    }
}
