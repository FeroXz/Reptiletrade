<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Trust;

/**
 * Liest config/trust.php und baut daraus die Bausteine der Missbrauchsabwehr.
 *
 * Wie beim Regelwerk der Rechts-Engine gilt: Ein unbekannter Schluessel oder
 * ein falscher Typ fuehrt sofort zu einem Fehler. Eine stillschweigend
 * uebersprungene Schutzmassnahme waere schlimmer als ein Startfehler.
 */
final readonly class TrustConfiguration
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private array $config) {}

    public function contactMasker(): ContactMasker
    {
        $section = $this->section('kontaktmaskierung');

        return new ContactMasker(
            $this->bool($section, 'enabled', true, 'kontaktmaskierung'),
            $this->positiveInt($section, 'erste_n_nachrichten', 'kontaktmaskierung'),
        );
    }

    /**
     * @return array<string, RateLimit>
     */
    public function rateLimits(): array
    {
        $section = $this->section('rate_limits');
        $limits = [];

        foreach ($section as $name => $options) {
            if (!\is_string($name)) {
                throw new TrustConfigurationException('Rate-Limit-Namen muessen Zeichenketten sein.');
            }

            if (!\is_array($options)) {
                throw new TrustConfigurationException(\sprintf('Rate-Limit "%s" braucht ein Optionsfeld.', $name));
            }

            /** @var array<string, mixed> $options */
            $limits[$name] = new RateLimit(
                $name,
                $this->positiveInt($options, 'limit', 'rate_limits.' . $name),
                $this->positiveInt($options, 'fenster', 'rate_limits.' . $name),
            );
        }

        if ($limits === []) {
            throw new TrustConfigurationException('Es ist kein einziges Rate-Limit konfiguriert.');
        }

        return $limits;
    }

    public function keywordFilter(): FraudKeywordFilter
    {
        $section = $this->section('keywords');
        $rules = [];

        foreach ($section as $key => $options) {
            if (!\is_string($key)) {
                throw new TrustConfigurationException('Keyword-Gruppen brauchen einen Namen als Zeichenkette.');
            }

            if (!\is_array($options)) {
                throw new TrustConfigurationException(\sprintf('Keyword-Gruppe "%s" braucht ein Optionsfeld.', $key));
            }

            /** @var array<string, mixed> $options */
            $mode = KeywordMode::tryFrom($this->string($options, 'mode', 'keywords.' . $key));
            if ($mode === null) {
                throw new TrustConfigurationException(
                    \sprintf('Keyword-Gruppe "%s": "mode" muss "markieren" oder "sperre" sein.', $key),
                );
            }

            $words = $this->strings($options, 'words', 'keywords.' . $key);
            if ($words === []) {
                throw new TrustConfigurationException(\sprintf('Keyword-Gruppe "%s" enthaelt kein einziges Wort.', $key));
            }

            $rules[] = new KeywordRule($key, $mode, $this->string($options, 'reason', 'keywords.' . $key), $words);
        }

        $threshold = $this->config['markierung_ab_treffern'] ?? 1;
        if (!\is_int($threshold) || $threshold < 1) {
            throw new TrustConfigurationException('"markierung_ab_treffern" muss eine Zahl ab 1 sein.');
        }

        return new FraudKeywordFilter($rules, $threshold);
    }

    public function autoModeration(): AutoModerationPolicy
    {
        $section = $this->section('auto_moderation');

        return new AutoModerationPolicy(
            $this->bool($section, 'enabled', true, 'auto_moderation'),
            $this->positiveInt($section, 'erste_n_anzeigen', 'auto_moderation'),
            $this->positiveInt($section, 'gilt_bis_kontoalter_tage', 'auto_moderation'),
        );
    }

    /**
     * Wie viele Suchen ein Konto merken darf.
     */
    public function savedSearchLimit(): int
    {
        return $this->positiveInt($this->section('gespeicherte_suchen'), 'max_je_konto', 'gespeicherte_suchen');
    }

    /**
     * @return array<string, mixed>
     */
    private function section(string $name): array
    {
        $value = $this->config[$name] ?? null;

        if (!\is_array($value)) {
            throw new TrustConfigurationException(\sprintf('In config/trust.php fehlt der Abschnitt "%s".', $name));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function bool(array $options, string $name, bool $default, string $context): bool
    {
        $value = $options[$name] ?? $default;

        if (!\is_bool($value)) {
            throw new TrustConfigurationException(\sprintf('%s: "%s" muss true oder false sein.', $context, $name));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function positiveInt(array $options, string $name, string $context): int
    {
        $value = $options[$name] ?? null;

        if (!\is_int($value) || $value < 1) {
            throw new TrustConfigurationException(\sprintf('%s: "%s" muss eine Zahl ab 1 sein.', $context, $name));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function string(array $options, string $name, string $context): string
    {
        $value = $options[$name] ?? null;

        if (!\is_string($value) || $value === '') {
            throw new TrustConfigurationException(\sprintf('%s: "%s" muss eine nicht leere Zeichenkette sein.', $context, $name));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function strings(array $options, string $name, string $context): array
    {
        $value = $options[$name] ?? [];

        if (!\is_array($value)) {
            throw new TrustConfigurationException(\sprintf('%s: "%s" muss eine Liste sein.', $context, $name));
        }

        $strings = [];
        foreach ($value as $entry) {
            if (!\is_string($entry) || trim($entry) === '') {
                throw new TrustConfigurationException(\sprintf('%s: "%s" darf nur nicht leere Zeichenketten enthalten.', $context, $name));
            }
            $strings[] = $entry;
        }

        return $strings;
    }
}
