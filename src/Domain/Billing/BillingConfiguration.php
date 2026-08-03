<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Billing;

use Reptilienmarkt\Domain\Setting\Settings;

/**
 * Liest config/monetarisierung.php.
 *
 * Wie beim Regelwerk der Rechts-Engine und bei config/trust.php gilt: Ein
 * unbekannter Schluessel oder ein falscher Typ fuehrt sofort zu einem Fehler.
 * Bei Preisen ist das besonders wichtig — eine stillschweigend auf null
 * gefallene Zahl waere ein Geschenk an alle.
 */
final readonly class BillingConfiguration
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config,
        private ?Settings $settings = null,
    ) {}

    /**
     * Der Hauptschalter. Das Setting sticht die Konfigurationsdatei, damit sich
     * die Abrechnung ohne Deployment abschalten laesst.
     */
    public function enabled(): bool
    {
        $fromFile = $this->config['enabled'] ?? false;

        if (!\is_bool($fromFile)) {
            throw new BillingConfigurationException('"enabled" muss true oder false sein.');
        }

        $key = $this->config['setting_key'] ?? null;

        if ($this->settings === null || !\is_string($key) || $key === '') {
            return $fromFile;
        }

        return $this->settings->bool($key, $fromFile);
    }

    /**
     * @return array<string, Plan>
     */
    public function plans(): array
    {
        $section = $this->section('plans');
        $plans = [];

        foreach ($section as $key => $options) {
            if (!\is_string($key)) {
                throw new BillingConfigurationException('Tarifschluessel muessen Zeichenketten sein.');
            }

            if (!\is_array($options)) {
                throw new BillingConfigurationException(\sprintf('Tarif "%s" braucht ein Optionsfeld.', $key));
            }

            /** @var array<string, mixed> $options */
            $plans[$key] = new Plan(
                $key,
                $this->string($options, 'name', 'plans.' . $key),
                $this->string($options, 'description', 'plans.' . $key),
                new Money($this->nonNegativeInt($options, 'price_cents', 'plans.' . $key), 'EUR'),
                $this->interval($options, $key),
                $this->limits($options, $key),
                $this->features($options, $key),
            );
        }

        if ($plans === []) {
            throw new BillingConfigurationException('Es ist kein einziger Tarif konfiguriert.');
        }

        $default = $this->config['default_plan'] ?? null;
        if (!\is_string($default) || !isset($plans[$default])) {
            throw new BillingConfigurationException('"default_plan" muss auf einen vorhandenen Tarif zeigen.');
        }

        return $plans;
    }

    public function defaultPlanKey(): string
    {
        $default = $this->config['default_plan'] ?? null;

        if (!\is_string($default) || $default === '') {
            throw new BillingConfigurationException('"default_plan" fehlt.');
        }

        return $default;
    }

    /**
     * @return array<string, BoostOption>
     */
    public function boosts(): array
    {
        $section = $this->section('boosts');
        $boosts = [];

        foreach ($section as $key => $options) {
            if (!\is_string($key)) {
                throw new BillingConfigurationException('Boost-Schluessel muessen Zeichenketten sein.');
            }

            if (!\is_array($options)) {
                throw new BillingConfigurationException(\sprintf('Boost "%s" braucht ein Optionsfeld.', $key));
            }

            /** @var array<string, mixed> $options */
            $boosts[$key] = new BoostOption(
                $key,
                $this->string($options, 'name', 'boosts.' . $key),
                $this->positiveInt($options, 'days', 'boosts.' . $key),
                new Money($this->nonNegativeInt($options, 'price_cents', 'boosts.' . $key), 'EUR'),
            );
        }

        return $boosts;
    }

    public function providerName(): string
    {
        $provider = $this->section('provider');

        return $this->string($provider, 'name', 'provider');
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(string $name): array
    {
        $provider = $this->section('provider');
        $options = $provider[$name] ?? [];

        if (!\is_array($options)) {
            throw new BillingConfigurationException(\sprintf('provider.%s muss ein Feld sein.', $name));
        }

        /** @var array<string, mixed> $options */
        return $options;
    }

    public function currencyFor(string $country): string
    {
        $currencies = $this->section('currencies');
        $currency = $currencies[$country] ?? 'EUR';

        return \is_string($currency) && \strlen($currency) === 3 ? $currency : 'EUR';
    }

    public function taxRateFor(string $country): float
    {
        $tax = $this->section('tax');
        $rates = $tax['rates'] ?? [];

        if (!\is_array($rates)) {
            return 0.0;
        }

        $rate = $rates[$country] ?? 0.0;

        return \is_float($rate) || \is_int($rate) ? (float) $rate : 0.0;
    }

    public function pricesIncludeTax(): bool
    {
        $tax = $this->section('tax');

        return $this->bool($tax, 'included', true, 'tax');
    }

    // ------------------------------------------------------------ Hilfsmittel

    /**
     * @return array<string, mixed>
     */
    private function section(string $name): array
    {
        $value = $this->config[$name] ?? null;

        if (!\is_array($value)) {
            throw new BillingConfigurationException(
                \sprintf('In config/monetarisierung.php fehlt der Abschnitt "%s".', $name),
            );
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, int|null>
     */
    private function limits(array $options, string $plan): array
    {
        $raw = $options['limits'] ?? [];

        if (!\is_array($raw)) {
            throw new BillingConfigurationException(\sprintf('Tarif "%s": "limits" muss ein Feld sein.', $plan));
        }

        $limits = [];
        foreach ($raw as $name => $value) {
            if (!\is_string($name)) {
                throw new BillingConfigurationException(\sprintf('Tarif "%s": Grenzen brauchen Namen.', $plan));
            }

            // null ist erlaubt und bedeutet unbegrenzt — deshalb hier kein
            // stillschweigendes Umdeuten auf 0.
            if ($value !== null && (!\is_int($value) || $value < 0)) {
                throw new BillingConfigurationException(\sprintf(
                    'Tarif "%s": Grenze "%s" muss null oder eine Zahl ab 0 sein.',
                    $plan,
                    $name,
                ));
            }

            $limits[$name] = $value;
        }

        return $limits;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<Feature>
     */
    private function features(array $options, string $plan): array
    {
        $raw = $options['features'] ?? [];

        if (!\is_array($raw)) {
            throw new BillingConfigurationException(\sprintf('Tarif "%s": "features" muss eine Liste sein.', $plan));
        }

        $features = [];
        foreach ($raw as $entry) {
            if (!\is_string($entry)) {
                throw new BillingConfigurationException(\sprintf('Tarif "%s": Merkmale muessen Zeichenketten sein.', $plan));
            }

            $feature = Feature::tryFrom($entry);
            if ($feature === null) {
                throw new BillingConfigurationException(\sprintf('Tarif "%s": unbekanntes Merkmal "%s".', $plan, $entry));
            }

            $features[] = $feature;
        }

        return $features;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function interval(array $options, string $plan): BillingInterval
    {
        $value = $this->string($options, 'interval', 'plans.' . $plan);
        $interval = BillingInterval::tryFrom($value);

        if ($interval === null) {
            throw new BillingConfigurationException(\sprintf(
                'Tarif "%s": Abrechnungszeitraum "%s" ist unbekannt (erlaubt: keiner, monatlich, jaehrlich).',
                $plan,
                $value,
            ));
        }

        return $interval;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function bool(array $options, string $name, bool $default, string $context): bool
    {
        $value = $options[$name] ?? $default;

        if (!\is_bool($value)) {
            throw new BillingConfigurationException(\sprintf('%s: "%s" muss true oder false sein.', $context, $name));
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
            throw new BillingConfigurationException(
                \sprintf('%s: "%s" muss eine nicht leere Zeichenkette sein.', $context, $name),
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function nonNegativeInt(array $options, string $name, string $context): int
    {
        $value = $options[$name] ?? null;

        if (!\is_int($value) || $value < 0) {
            throw new BillingConfigurationException(\sprintf('%s: "%s" muss eine Zahl ab 0 sein.', $context, $name));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function positiveInt(array $options, string $name, string $context): int
    {
        $value = $this->nonNegativeInt($options, $name, $context);

        if ($value < 1) {
            throw new BillingConfigurationException(\sprintf('%s: "%s" muss eine Zahl ab 1 sein.', $context, $name));
        }

        return $value;
    }
}
