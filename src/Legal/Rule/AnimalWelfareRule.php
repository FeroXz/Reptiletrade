<?php

declare(strict_types=1);

namespace Reptilienmarkt\Legal\Rule;

use Reptilienmarkt\Domain\Listing\ListingType;
use Reptilienmarkt\Legal\LegalContext;
use Reptilienmarkt\Legal\LegalDecisionDraft;
use Reptilienmarkt\Legal\LegalRule;
use Reptilienmarkt\Legal\NoticeSeverity;
use Reptilienmarkt\Support\Clock;

/**
 * Regel 6 — Tierschutz: Mindestabgabealter und -gewicht.
 *
 * Prueft min_abgabe_alter_wochen und min_abgabe_gewicht_g der Art gegen
 * Schlupfdatum und Gewicht der Anzeige und blockiert bei Unterschreitung.
 *
 * Nachzucht-Vorbestellungen sind ausgenommen: Dort existiert das Tier noch
 * nicht. Statt einer Blockade gibt es den Hinweis, ab wann abgegeben werden darf.
 */
final readonly class AnimalWelfareRule implements LegalRule
{
    public function __construct(
        private Clock $clock,
        private bool $requireData,
        private string $hatchDateField,
        private string $weightField,
        private string $textKey,
    ) {}

    public function key(): string
    {
        return 'tierschutz';
    }

    public function applies(LegalContext $context): bool
    {
        if (!$context->describesAnimalOnOffer()) {
            return false;
        }

        return $context->species->minAbgabeAlterWochen !== null
            || $context->species->minAbgabeGewichtG !== null;
    }

    public function evaluate(LegalContext $context, LegalDecisionDraft $draft): void
    {
        $species = $context->species;
        $isPreorder = $context->type === ListingType::NachzuchtVorbestellung;

        $draft->notice($this->textKey, $context->jurisdiction(), NoticeSeverity::Info);

        if ($isPreorder) {
            $draft->note($this->key(), 'vorbestellung_ausgenommen', [
                'art' => $species->scientificName,
                'min_alter_wochen' => $species->minAbgabeAlterWochen,
                'min_gewicht_g' => $species->minAbgabeGewichtG,
            ]);

            return;
        }

        $this->checkAge($context, $draft);
        $this->checkWeight($context, $draft);
    }

    private function checkAge(LegalContext $context, LegalDecisionDraft $draft): void
    {
        $minimum = $context->species->minAbgabeAlterWochen;
        if ($minimum === null) {
            return;
        }

        if ($this->requireData) {
            $draft->requireField($this->hatchDateField, $this->key());
        }

        $hatchDate = $context->hatchDate;
        $art = $context->species->scientificName;

        if ($hatchDate === null) {
            if ($this->requireData) {
                $draft->block($this->key(), 'schlupfdatum_fehlt', ['art' => $art, 'min_alter_wochen' => $minimum]);
            }

            return;
        }

        $now = $this->clock->now();

        if ($hatchDate > $now) {
            $draft->block($this->key(), 'schlupfdatum_in_zukunft', [
                'art' => $art,
                'schlupfdatum' => $hatchDate->format('Y-m-d'),
            ]);

            return;
        }

        $ageWeeks = intdiv((int) $hatchDate->diff($now)->days, 7);

        if ($ageWeeks < $minimum) {
            $draft->block($this->key(), 'abgabealter_unterschritten', [
                'art' => $art,
                'alter_wochen' => $ageWeeks,
                'min_alter_wochen' => $minimum,
            ]);
        }
    }

    private function checkWeight(LegalContext $context, LegalDecisionDraft $draft): void
    {
        $minimum = $context->species->minAbgabeGewichtG;
        if ($minimum === null) {
            return;
        }

        if ($this->requireData) {
            $draft->requireField($this->weightField, $this->key());
        }

        $weight = $context->weightG;
        $art = $context->species->scientificName;

        if ($weight === null) {
            if ($this->requireData) {
                $draft->block($this->key(), 'gewicht_fehlt', ['art' => $art, 'min_gewicht_g' => $minimum]);
            }

            return;
        }

        if ($weight < $minimum) {
            $draft->block($this->key(), 'abgabegewicht_unterschritten', [
                'art' => $art,
                'gewicht_g' => $weight,
                'min_gewicht_g' => $minimum,
            ]);
        }
    }
}
