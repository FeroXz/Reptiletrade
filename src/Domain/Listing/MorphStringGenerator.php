<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Listing;

use Reptilienmarkt\Domain\Species\Inheritance;

/**
 * Erzeugt Anzeige-String und Genotyp-Kurzform aus den Merkmalen einer Anzeige.
 *
 * Reihenfolge im Anzeige-String: erst die sichtbaren Merkmale, dann die
 * sicheren het-Angaben, zuletzt die moeglichen. Innerhalb einer Gruppe
 * alphabetisch, damit dieselbe Auswahl immer denselben String ergibt —
 * sonst wuerden Suchtreffer und Titel je nach Eingabereihenfolge abweichen.
 *
 * Genotyp-Kurzform: Ein rezessives sichtbares Merkmal ist homozygot
 * ("hypo/hypo"), eine het-Angabe traegt das Wildtyp-Allel ("zero/+"), eine
 * moegliche het ein Fragezeichen ("zero/?"). Merkmale ohne einzelnen Genort
 * (polygen, liniengezuechtet, Paradox) haben keine Kurzform und entfallen.
 */
final readonly class MorphStringGenerator implements GeneticsCalculator
{
    private const string WILDTYPE = '+';

    public function morphString(array $selection): string
    {
        if ($selection === []) {
            return '';
        }

        $groups = $this->group($selection);
        $parts = [];

        foreach ($groups[Zygosity::Visual->value] as $entry) {
            $parts[] = $entry->shortLabel();
        }

        foreach ($groups[Zygosity::Het->value] as $entry) {
            $parts[] = 'het ' . $entry->shortLabel();
        }

        foreach ($groups[Zygosity::PossHet66->value] as $entry) {
            $parts[] = '66% poss. het ' . $entry->shortLabel();
        }

        foreach ($groups[Zygosity::PossHet50->value] as $entry) {
            $parts[] = '50% poss. het ' . $entry->shortLabel();
        }

        return implode(' ', $parts);
    }

    public function genotype(array $selection): string
    {
        $parts = [];

        foreach ($this->sorted($selection) as $entry) {
            $notation = $this->notationFor($entry);
            if ($notation !== null) {
                $parts[] = $notation;
            }
        }

        return implode(' ', $parts);
    }

    public function warnings(array $selection): array
    {
        $warnings = [];

        foreach ($selection as $entry) {
            if ($entry->hasInconsistentZygosity()) {
                $warnings[] = \sprintf(
                    '"%s" wird %s vererbt — eine het-Angabe ergibt dort keinen Sinn.',
                    $entry->morph->name,
                    $entry->morph->inheritance->label(),
                );
            }

            if ($entry->morph->isLethalCombo && $entry->isVisual()) {
                $warnings[] = \sprintf(
                    'Bei "%s" ist die homozygote Form nicht lebensfähig. Verpaarungen zweier Tiere mit diesem '
                    . 'Merkmal führen zu nicht lebensfähigen Nachkommen.',
                    $entry->morph->name,
                );
            }
        }

        foreach ($this->allelicPairs($selection) as [$first, $second]) {
            $warnings[] = \sprintf(
                '"%s" und "%s" besetzen denselben Genort. Ein Tier kann beide nur als Allelkombination tragen — '
                . 'bitte prüfen, ob die Angabe stimmt.',
                $first->morph->name,
                $second->morph->name,
            );
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param list<MorphSelection> $selection
     *
     * @return array<string, list<MorphSelection>>
     */
    private function group(array $selection): array
    {
        $groups = [
            Zygosity::Visual->value => [],
            Zygosity::Het->value => [],
            Zygosity::PossHet66->value => [],
            Zygosity::PossHet50->value => [],
        ];

        foreach ($this->sorted($selection) as $entry) {
            $groups[$entry->zygosity->value][] = $entry;
        }

        return $groups;
    }

    /**
     * @param list<MorphSelection> $selection
     *
     * @return list<MorphSelection>
     */
    private function sorted(array $selection): array
    {
        $sorted = $selection;

        usort(
            $sorted,
            static fn(MorphSelection $a, MorphSelection $b): int => strcasecmp($a->shortLabel(), $b->shortLabel()),
        );

        return $sorted;
    }

    private function notationFor(MorphSelection $entry): ?string
    {
        $locus = mb_strtolower($entry->shortLabel());

        return match ($entry->morph->inheritance) {
            Inheritance::Recessive => match ($entry->zygosity) {
                Zygosity::Visual => $locus . '/' . $locus,
                Zygosity::Het => $locus . '/' . self::WILDTYPE,
                Zygosity::PossHet66, Zygosity::PossHet50 => $locus . '/?',
            },
            // Dominant und unvollstaendig dominant zeigen sich bereits mit
            // einem Allel; Superformen stehen als eigener Katalogeintrag.
            Inheritance::Dominant, Inheritance::IncompleteDominant => $locus . '/' . self::WILDTYPE,
            default => null,
        };
    }

    /**
     * @param list<MorphSelection> $selection
     *
     * @return list<array{MorphSelection, MorphSelection}>
     */
    private function allelicPairs(array $selection): array
    {
        $pairs = [];
        $count = \count($selection);

        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                if ($selection[$i]->morph->sharesLocusWith($selection[$j]->morph)) {
                    $pairs[] = [$selection[$i], $selection[$j]];
                }
            }
        }

        return $pairs;
    }
}
