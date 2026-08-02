<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal\Rule;

use Reptilienmarkt\Domain\Setting\Settings;
use Reptilienmarkt\Legal\LegalContext;
use Reptilienmarkt\Legal\LegalDecisionDraft;
use Reptilienmarkt\Legal\LegalRule;
use Reptilienmarkt\Legal\NoticeSeverity;

/**
 * Regel 5 — Gefahrtiere.
 *
 * Gefahrtierverordnungen sind Landes- bzw. Kantonsrecht und weichen stark
 * voneinander ab. Die Zuordnung Region -> Modus steht deshalb vollstaendig in
 * der Konfiguration; ist eine Region nicht hinterlegt, greift der konfigurierte
 * Standardmodus. Ueber das Setting laesst sich die Regel global abschalten.
 */
final readonly class DangerousAnimalRule implements LegalRule
{
    /**
     * @param array<string, array<string, DangerousAnimalMode>> $regions Land -> Bundesland/Kanton -> Modus
     */
    public function __construct(
        private Settings $settings,
        private array $regions,
        private DangerousAnimalMode $defaultMode,
        private bool $reviewOnWarning,
        private string $settingKey,
        private string $textKey,
    ) {}

    public function key(): string
    {
        return 'gefahrtier';
    }

    public function applies(LegalContext $context): bool
    {
        if (!$context->species->gefahrtier) {
            return false;
        }

        return $this->settings->bool($this->settingKey, true);
    }

    public function evaluate(LegalContext $context, LegalDecisionDraft $draft): void
    {
        $mode = $this->modeFor($context->country->value, $context->admin1);

        $data = [
            'art' => $context->species->scientificName,
            'land' => $context->country->value,
            'region' => $context->admin1,
            'modus' => $mode->value,
        ];

        if ($mode === DangerousAnimalMode::Keine) {
            $draft->note($this->key(), 'keine_gefahrtierverordnung', $data);

            return;
        }

        $draft->notice(
            $this->textKey,
            $context->jurisdiction(),
            $mode === DangerousAnimalMode::Sperre ? NoticeSeverity::Kritisch : NoticeSeverity::Warnung,
        );

        if ($mode === DangerousAnimalMode::Sperre) {
            $draft->block($this->key(), 'gefahrtier_gesperrt', $data);

            return;
        }

        $draft->note($this->key(), 'gefahrtier_warnung', $data);

        if ($this->reviewOnWarning) {
            $draft->requireReview($this->key(), 'gefahrtier_pruefung', $data);
        }
    }

    private function modeFor(string $country, ?string $admin1): DangerousAnimalMode
    {
        if ($admin1 === null) {
            return $this->defaultMode;
        }

        return $this->regions[$country][$admin1] ?? $this->defaultMode;
    }
}
