<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal\Rule;

use Reptilienmarkt\Domain\Geo\Country;
use Reptilienmarkt\Domain\Listing\Handover;
use Reptilienmarkt\Legal\LegalContext;
use Reptilienmarkt\Legal\LegalDecisionDraft;
use Reptilienmarkt\Legal\LegalRule;
use Reptilienmarkt\Legal\NoticeSeverity;

/**
 * Regel 7 — Versand.
 *
 * Die Uebergabeart "tiertransport" ist nur in den konfigurierten Laendern
 * waehlbar. Der Hinweis ist fest: kein Versand ueber Paketdienste, ausschliesslich
 * zertifizierter Tiertransport.
 */
final readonly class ShippingRule implements LegalRule
{
    /**
     * @param list<Country> $allowedCountries
     */
    public function __construct(
        private array $allowedCountries,
        private string $textKey,
    ) {}

    public function key(): string
    {
        return 'versand';
    }

    public function applies(LegalContext $context): bool
    {
        return $context->handover === Handover::Tiertransport;
    }

    public function evaluate(LegalContext $context, LegalDecisionDraft $draft): void
    {
        $draft->notice($this->textKey, $context->jurisdiction(), NoticeSeverity::Warnung);

        $data = [
            'land' => $context->country->value,
            'uebergabe' => $context->handover->value,
        ];

        if (!\in_array($context->country, $this->allowedCountries, true)) {
            $draft->block($this->key(), 'tiertransport_nicht_zulaessig', $data);

            return;
        }

        $draft->note($this->key(), 'tiertransport_zulaessig', $data);
    }
}
