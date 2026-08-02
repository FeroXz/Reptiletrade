<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal;

use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Setting\Settings;
use Reptilienmarkt\Domain\Species\BnatschgStatus;
use Reptilienmarkt\Domain\Species\EuAnnex;
use Reptilienmarkt\Legal\Rule\AnimalWelfareRule;
use Reptilienmarkt\Legal\Rule\AnnexACertificateRule;
use Reptilienmarkt\Legal\Rule\AnnexBProvenanceRule;
use Reptilienmarkt\Legal\Rule\CommercialActivityRule;
use Reptilienmarkt\Legal\Rule\DangerousAnimalMode;
use Reptilienmarkt\Legal\Rule\DangerousAnimalRule;
use Reptilienmarkt\Legal\Rule\MarkingRule;
use Reptilienmarkt\Legal\Rule\ReportingObligationRule;
use Reptilienmarkt\Legal\Rule\ShippingRule;
use Reptilienmarkt\Support\Clock;

/**
 * Baut das Regelwerk aus config/legal_rules.php. Unbekannte Regelschluessel und
 * falsche Werte fuehren sofort zu einem Fehler — ein Tippfehler in der
 * Konfiguration soll nicht als stillschweigend abgeschaltete Regel enden.
 */
final readonly class LegalRuleFactory
{
    public function __construct(
        private Clock $clock,
        private Settings $settings,
    ) {}

    /**
     * @param array<string, mixed> $config
     *
     * @return list<LegalRule>
     */
    public function fromConfig(array $config): array
    {
        $rules = $config['rules'] ?? null;
        if (!\is_array($rules)) {
            throw new LegalConfigurationException('Das Regelwerk braucht einen Abschnitt "rules".');
        }

        $built = [];

        foreach ($rules as $key => $options) {
            if (!\is_string($key)) {
                throw new LegalConfigurationException('Regelschluessel muessen Zeichenketten sein.');
            }

            if (!\is_array($options)) {
                throw new LegalConfigurationException(\sprintf('Regel "%s" braucht ein Optionsfeld.', $key));
            }

            /** @var array<string, mixed> $options */
            if (!$this->bool($options, 'enabled', true, $key)) {
                continue;
            }

            $built[] = $this->build($key, $options);
        }

        return $built;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function build(string $key, array $options): LegalRule
    {
        return match ($key) {
            'anhang_a' => new AnnexACertificateRule(
                $this->annexes($options, 'annexes', $key),
                $this->bnatschgStatuses($options, 'bnatschg_statuses', $key),
                $this->strings($options, 'required_fields', $key),
                $this->string($options, 'text_key', $key),
            ),
            'anhang_b' => new AnnexBProvenanceRule(
                $this->annexes($options, 'annexes', $key),
                $this->annexes($options, 'excluded_annexes', $key),
                $this->bool($options, 'include_doku_pflicht', true, $key),
                $this->bool($options, 'block_when_missing', true, $key),
                $this->strings($options, 'required_fields', $key),
                $this->string($options, 'text_key', $key),
            ),
            'meldepflicht' => new ReportingObligationRule(
                $this->clock,
                $this->strings($options, 'required_fields', $key),
                $this->string($options, 'confirmation_field', $key),
                $this->string($options, 'date_field', $key),
                $this->string($options, 'text_key', $key),
            ),
            'kennzeichnung' => new MarkingRule(
                $this->strings($options, 'genera', $key),
                $this->strings($options, 'allowed_methods', $key),
                $this->strings($options, 'methods_needing_code', $key),
                $this->string($options, 'method_field', $key),
                $this->string($options, 'code_field', $key),
                $this->string($options, 'text_key', $key),
            ),
            'gefahrtier' => new DangerousAnimalRule(
                $this->settings,
                $this->regions($options, $key),
                $this->mode($options, 'default_mode', $key),
                $this->bool($options, 'review_on_warning', false, $key),
                $this->string($options, 'setting_key', $key),
                $this->string($options, 'text_key', $key),
            ),
            'tierschutz' => new AnimalWelfareRule(
                $this->clock,
                $this->bool($options, 'require_data', true, $key),
                $this->string($options, 'hatch_date_field', $key),
                $this->string($options, 'weight_field', $key),
                $this->string($options, 'text_key', $key),
            ),
            'versand' => new ShippingRule(
                $this->countries($options, 'allowed_countries', $key),
                $this->string($options, 'text_key', $key),
            ),
            'gewerblichkeit' => new CommercialActivityRule(
                $this->int($options, 'active_listing_threshold', $key),
                $this->int($options, 'sales_per_year_threshold', $key),
                $this->strings($options, 'required_fields', $key),
                $this->string($options, 'text_key', $key),
            ),
            default => throw new LegalConfigurationException(\sprintf('Unbekannte Regel "%s" im Regelwerk.', $key)),
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function bool(array $options, string $name, bool $default, string $rule): bool
    {
        $value = $options[$name] ?? $default;

        if (!\is_bool($value)) {
            throw new LegalConfigurationException(\sprintf('Regel "%s": "%s" muss true oder false sein.', $rule, $name));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function int(array $options, string $name, string $rule): int
    {
        $value = $options[$name] ?? null;

        if (!\is_int($value)) {
            throw new LegalConfigurationException(\sprintf('Regel "%s": "%s" muss eine ganze Zahl sein.', $rule, $name));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function string(array $options, string $name, string $rule): string
    {
        $value = $options[$name] ?? null;

        if (!\is_string($value) || $value === '') {
            throw new LegalConfigurationException(\sprintf('Regel "%s": "%s" muss eine nicht leere Zeichenkette sein.', $rule, $name));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function strings(array $options, string $name, string $rule): array
    {
        $value = $options[$name] ?? [];

        if (!\is_array($value)) {
            throw new LegalConfigurationException(\sprintf('Regel "%s": "%s" muss eine Liste sein.', $rule, $name));
        }

        $strings = [];
        foreach ($value as $entry) {
            if (!\is_string($entry)) {
                throw new LegalConfigurationException(\sprintf('Regel "%s": "%s" darf nur Zeichenketten enthalten.', $rule, $name));
            }
            $strings[] = $entry;
        }

        return $strings;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<EuAnnex>
     */
    private function annexes(array $options, string $name, string $rule): array
    {
        return array_map(
            static function (string $value) use ($rule, $name): EuAnnex {
                $annex = EuAnnex::tryFrom($value);
                if ($annex === null) {
                    throw new LegalConfigurationException(
                        \sprintf('Regel "%s": "%s" kennt den EU-Anhang "%s" nicht.', $rule, $name, $value),
                    );
                }

                return $annex;
            },
            $this->strings($options, $name, $rule),
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<BnatschgStatus>
     */
    private function bnatschgStatuses(array $options, string $name, string $rule): array
    {
        return array_map(
            static function (string $value) use ($rule, $name): BnatschgStatus {
                $status = BnatschgStatus::tryFrom($value);
                if ($status === null) {
                    throw new LegalConfigurationException(
                        \sprintf('Regel "%s": "%s" kennt den Schutzstatus "%s" nicht.', $rule, $name, $value),
                    );
                }

                return $status;
            },
            $this->strings($options, $name, $rule),
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<Country>
     */
    private function countries(array $options, string $name, string $rule): array
    {
        return array_map(
            static function (string $value) use ($rule, $name): Country {
                $country = Country::tryFrom($value);
                if ($country === null) {
                    throw new LegalConfigurationException(
                        \sprintf('Regel "%s": "%s" kennt das Land "%s" nicht.', $rule, $name, $value),
                    );
                }

                return $country;
            },
            $this->strings($options, $name, $rule),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function mode(array $options, string $name, string $rule): DangerousAnimalMode
    {
        $value = $this->string($options, $name, $rule);
        $mode = DangerousAnimalMode::tryFrom($value);

        if ($mode === null) {
            throw new LegalConfigurationException(
                \sprintf('Regel "%s": "%s" kennt den Modus "%s" nicht (erlaubt: sperre, warnung, keine).', $rule, $name, $value),
            );
        }

        return $mode;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, array<string, DangerousAnimalMode>>
     */
    private function regions(array $options, string $rule): array
    {
        $value = $options['regions'] ?? [];

        if (!\is_array($value)) {
            throw new LegalConfigurationException(\sprintf('Regel "%s": "regions" muss ein Feld sein.', $rule));
        }

        $regions = [];

        foreach ($value as $country => $entries) {
            if (!\is_string($country) || Country::tryFrom($country) === null) {
                throw new LegalConfigurationException(
                    \sprintf('Regel "%s": "regions" kennt das Land "%s" nicht.', $rule, \is_string($country) ? $country : get_debug_type($country)),
                );
            }

            if (!\is_array($entries)) {
                throw new LegalConfigurationException(\sprintf('Regel "%s": "regions.%s" muss ein Feld sein.', $rule, $country));
            }

            foreach ($entries as $region => $mode) {
                if (!\is_string($region) || !\is_string($mode)) {
                    throw new LegalConfigurationException(
                        \sprintf('Regel "%s": "regions.%s" braucht Region => Modus als Zeichenketten.', $rule, $country),
                    );
                }

                $parsed = DangerousAnimalMode::tryFrom($mode);
                if ($parsed === null) {
                    throw new LegalConfigurationException(
                        \sprintf('Regel "%s": Modus "%s" bei %s/%s ist unbekannt.', $rule, $mode, $country, $region),
                    );
                }

                $regions[$country][$region] = $parsed;
            }
        }

        return $regions;
    }
}
